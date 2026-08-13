<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\ForwardMessage as PassItOn;
use App\Features\MessageForwarding;
use App\Models\Message;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Pass the message that set this off along to another channel.
 *
 * Not the same as writing a new message with the text in it, which is why this
 * exists beside send-channel-message: what lands keeps its attribution, so the
 * channel sees who originally said it rather than a quote from a bot. For "meld
 * elke storingsmelding ook in #directie" that difference is the whole point.
 *
 * The message comes from the trigger unless a step names another — the
 * convention every message action here follows.
 */
class ForwardMessage extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly PassItOn $passItOn) {}

    public static function label(): string
    {
        return __('workflows.actions.forward-message.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.forward-message.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::text(
                'message_id',
                __('workflows.actions.fields.message'),
                __('workflows.actions.fields.message_hint'),
                required: false,
            ),
            WorkflowField::longText(
                'note',
                __('workflows.actions.forward-message.note.label'),
                __('workflows.actions.forward-message.note.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'message.id' => __('workflows.provides.message.id'),
            'channel.id' => __('workflows.provides.channel.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(MessageForwarding::class)) {
            throw new RuntimeException(__('workflows.errors.forwarding_off'));
        }

        $message = $this->message($context);
        $channel = $this->channel($context);
        $forwarder = $this->actor($context);

        /*
         * That the owner may see where it came from is already settled by
         * message(); this is the other half — that they may say anything in the
         * room it is going to. Forwarding is the one action that touches two
         * channels, and both questions have to be asked.
         */
        if ($forwarder->cannot('post', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_post', [
                'channel' => (string) $channel->name,
            ]));
        }

        $note = trim((string) $context->setting('note', ''));

        $forwarded = $this->passItOn->handle(
            $message,
            $channel,
            $forwarder,
            $note === '' ? null : $note,
        );

        return [
            'message' => ['id' => $forwarded->id],
            'channel' => ['id' => $channel->id],
        ];
    }
}
