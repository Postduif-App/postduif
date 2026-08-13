<?php

namespace App\Workflows\Actions;

use App\Actions\SharedChannels\ShareChannelWithWorkspace;
use App\Features\SharedChannels;
use App\Models\Workspace;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Offer a channel to another workspace.
 *
 * Nothing is granted by this step. What it writes is an invitation that stays
 * inert until somebody on the other side accepts it — see
 * ShareChannelWithWorkspace, where that is the whole difference between this
 * feature and adding an outsider to a channel. A workflow cannot decide that
 * another organisation is now reading along.
 *
 * The other workspace is named by its slug, in a plain text field that takes a
 * variable. No picker, and that is a decision rather than an omission: a
 * control drawn for exactly one action is a control nobody recognises, and the
 * slug is what the manual button already asks for — see ChannelShareController,
 * which validates the same "exists:workspaces,slug" and no more. A workflow
 * here does what a beheerder pressing that button does, with the same guards
 * and the same answer required from the other side.
 *
 * Re-offering a live share is how the terms are changed, and it clears the
 * acceptance: widening "mag meelezen" into "mag meepraten" is a new offer, and
 * the other side has to be asked again.
 */
class ShareChannel extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.share-channel.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.share-channel.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::text(
                'workspace',
                __('workflows.actions.share-channel.workspace.label'),
                __('workflows.actions.share-channel.workspace.hint'),
            ),
            WorkflowField::choice(
                'can_post',
                __('workflows.actions.share-channel.can_post.label'),
                [
                    'yes' => __('workflows.actions.share-channel.can_post.yes'),
                    'no' => __('workflows.actions.share-channel.can_post.no'),
                ],
                __('workflows.actions.share-channel.can_post.hint'),
                // Optional, because leaving it alone means the ordinary answer:
                // a guest who may read but not write is the narrower case, and
                // a required dropdown would make somebody state the obvious.
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'share.id' => __('workflows.provides.share.id'),
            'share.can_post' => __('workflows.provides.share.can_post'),
            'guest.id' => __('workflows.provides.share.guest_id'),
            'guest.name' => __('workflows.provides.share.guest_name'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
        ];
    }

    public function __construct(private readonly ShareChannelWithWorkspace $share) {}

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(SharedChannels::class)) {
            throw new RuntimeException(__('workflows.errors.shared_channels_off'));
        }

        $channel = $this->channel($context);

        /*
         * Ownership, not reachability — the one question here where those must
         * not be the same. A channel that merely reaches into this workspace is
         * somebody else's, and offering it onward would be a guest subletting
         * the host's room to a third company. The same check the endpoint makes.
         */
        if ($channel->workspace_id !== $context->workspace()->id) {
            throw new RuntimeException(__('workflows.errors.not_our_channel'));
        }

        if ($this->actor($context)->cannot('manageSettings', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_share_channel'));
        }

        $guest = $this->guest($context);

        /*
         * Whatever ShareChannelWithWorkspace refuses is thrown on as it is. Its
         * sentences are the useful ones — "that workspace does not accept
         * shared channels" and "that is your own workspace" are different
         * problems — and the runner puts the message on the run screen, which
         * is where somebody finds out which.
         */
        $share = $this->share->handle(
            $channel,
            $guest,
            $this->actor($context),
            // Absent means "mag meepraten", which is what the endpoint defaults
            // to and what somebody sharing a channel usually means.
            $context->setting('can_post') !== 'no',
        );

        return [
            'share' => ['id' => $share->id, 'can_post' => $share->can_post],
            'guest' => ['id' => $guest->id, 'name' => $guest->name],
            'channel' => ['id' => $channel->id, 'name' => $channel->name],
        ];
    }

    /**
     * The workspace a slug names.
     *
     * Trimmed and compared without case, because this value is typed by a
     * person or comes out of a variable that was — and a slug arriving as
     * "Bakker-BV" is the same organisation to everybody except a database.
     */
    private function guest(WorkflowStepContext $context): Workspace
    {
        $slug = trim((string) $context->setting('workspace'));

        if ($slug === '') {
            throw new RuntimeException(__('workflows.errors.no_workspace_named'));
        }

        $guest = Workspace::query()
            ->whereRaw('lower(slug) = ?', [mb_strtolower($slug)])
            ->first();

        if ($guest === null) {
            throw new RuntimeException(__('workflows.errors.workspace_not_found', ['slug' => $slug]));
        }

        return $guest;
    }
}
