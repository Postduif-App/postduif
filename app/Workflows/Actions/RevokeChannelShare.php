<?php

namespace App\Workflows\Actions;

use App\Actions\SharedChannels\RevokeChannelShare as Revoker;
use App\Enums\WorkflowRecordType;
use App\Features\SharedChannels;
use App\Models\ChannelShare;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * End an arrangement with another workspace.
 *
 * The counterpart of sharing, and the heavier of the two: the row is stamped,
 * and everybody who was only in that channel through this arrangement is taken
 * out of it — see RevokeChannelShare, and the case it is careful about, which
 * is somebody who belongs to both workspaces and was never a guest at all.
 *
 * What it does not touch is anything already said. A shared channel is a
 * conversation two organisations had, and clearing one side of it when the
 * arrangement ends would rewrite the other side's history too.
 *
 * From either side, because both may close their own half — asked through
 * ChannelSharePolicy::sever, which is the same question the button in the panel
 * asks. Ending one that is already ended does nothing and says so quietly: a
 * workflow that fires twice should not fail the second time for having got what
 * it wanted.
 */
class RevokeChannelShare extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.revoke-channel-share.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.revoke-channel-share.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'share_id',
                WorkflowRecordType::ChannelShare,
                __('workflows.actions.fields.share'),
                __('workflows.actions.fields.share_hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'share.id' => __('workflows.provides.share.id'),
            'revoked' => __('workflows.provides.share.revoked_now'),
            'guest.id' => __('workflows.provides.share.guest_id'),
            'guest.name' => __('workflows.provides.share.guest_name'),
            'channel.id' => __('workflows.provides.channel.id'),
            'channel.name' => __('workflows.provides.channel.name'),
        ];
    }

    public function __construct(private readonly Revoker $revoke) {}

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(SharedChannels::class)) {
            throw new RuntimeException(__('workflows.errors.shared_channels_off'));
        }

        $share = $this->record($context, WorkflowRecordType::ChannelShare);

        // Never false in practice — find() queries that model. Said out loud
        // because this is where it should stop if that ever stops being true.
        if (! $share instanceof ChannelShare) {
            throw new RuntimeException(__('workflows.errors.record_not_found', [
                'what' => WorkflowRecordType::ChannelShare->label(),
            ]));
        }

        /*
         * Which side this workflow is standing on decides whether it may end
         * this at all — a host must not reach it by way of the guest's right or
         * the other way round.
         */
        if ($this->actor($context)->cannot('sever', [$share, $context->workspace()])) {
            throw new RuntimeException(__('workflows.errors.may_not_sever_share'));
        }

        $revoked = $share->revoked_at === null;

        if ($revoked) {
            $this->revoke->handle($share);
        }

        $share->loadMissing(['channel:id,name', 'workspace:id,name']);

        return [
            'share' => ['id' => $share->id],
            // False when it was already ended, which is not a failure.
            'revoked' => $revoked,
            'guest' => ['id' => $share->workspace_id, 'name' => $share->workspace?->name],
            'channel' => ['id' => $share->channel_id, 'name' => $share->channel?->name],
        ];
    }
}
