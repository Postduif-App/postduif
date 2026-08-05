<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\SendMessage;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Say something in a channel.
 *
 * Through the ordinary SendMessage, which is the point of it: a workflow's
 * message is a message, and a second posting path would be a second place for
 * mentions, unread counts and broadcasts to be forgotten.
 *
 * Posted as a bot under the workflow's own name rather than as the person who
 * wrote the workflow. Their rights decide whether it may be posted at all, but
 * their name on it would mean a colleague appearing to say something they never
 * said — which is not a thing to leave to whoever reads carefully.
 */
class SendChannelMessage extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly SendMessage $sendMessage) {}

    public static function label(): string
    {
        return __('workflows.actions.send-channel-message.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.send-channel-message.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),
            WorkflowField::longText('body', __('workflows.actions.fields.body'), __('workflows.actions.fields.body_hint')),
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
        $channel = $this->channel($context);
        $body = trim((string) $context->setting('body', ''));

        /*
         * Checked here rather than only when the workflow was written: a
         * channel can be closed to everyone but its admins long after somebody
         * pointed a workflow at it, and the owner may have been taken out of it.
         */
        if ($this->actor($context)->cannot('post', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_post', ['channel' => $channel->name]));
        }

        /*
         * An empty body after the variables were filled in. That is not an
         * error in the workflow so much as a run where the thing it meant to
         * quote turned out to be missing — said plainly, because an empty
         * message posted into a channel would be worse.
         */
        if ($body === '') {
            throw new RuntimeException(__('workflows.errors.empty_message'));
        }

        $message = $this->sendMessage->fromSystem(
            $channel,
            $body,
            $this->botName($context),
            workflow: $context->workflow,
        );

        return [
            'message' => ['id' => $message->id],
            'channel' => ['id' => $channel->id],
        ];
    }
}
