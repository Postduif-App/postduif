<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\SendMessage;
use App\Actions\Chat\StartDirectMessage;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Say something to one person.
 *
 * The conversation used is the one between the workflow's owner and the
 * recipient — there is no such thing as a DM with a bot here, and inventing one
 * would mean a second kind of channel for a single feature to live in.
 *
 * The message itself is still a bot message, so what the recipient sees is
 * "workflow X said this" in a conversation they already had, rather than words
 * apparently typed by a colleague who was asleep at the time.
 */
class SendDirectMessage extends WorkflowAction
{
    use FindsTargets;

    public function __construct(
        private readonly StartDirectMessage $startDirectMessage,
        private readonly SendMessage $sendMessage,
    ) {}

    public static function label(): string
    {
        return __('workflows.actions.send-direct-message.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.send-direct-message.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::member('user_id', __('workflows.actions.fields.person')),
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
        $owner = $this->actor($context);
        $recipient = $this->member($context);
        $body = trim((string) $context->setting('body', ''));

        if ($body === '') {
            throw new RuntimeException(__('workflows.errors.empty_message'));
        }

        /*
         * The owner may not be allowed to address this person at all — a guest
         * is cut off from the workspace, and directMessage() is where that is
         * decided. Asked with the owner as the subject, because that is whose
         * rights the whole run uses.
         */
        if ($owner->cannot('directMessage', [$context->workspace(), $recipient])) {
            throw new RuntimeException(__('workflows.errors.may_not_dm'));
        }

        $channel = $this->startDirectMessage->handle($context->workspace(), $owner, $recipient);

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
