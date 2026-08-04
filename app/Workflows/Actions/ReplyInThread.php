<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\SendMessage;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Answer under a message rather than beside it.
 *
 * The message defaults to the one the trigger was about, which is what a reply
 * almost always means. Naming another is possible by writing a variable in, but
 * it is the exception the field exists for rather than the case it is built
 * around.
 *
 * A reply to a reply is hung under the same parent as the thing it answers, not
 * under the reply itself: threads here are one level deep, and a workflow must
 * not be the thing that invents a second.
 */
class ReplyInThread extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly SendMessage $sendMessage) {}

    public static function label(): string
    {
        return __('workflows.actions.reply-in-thread.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.reply-in-thread.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::longText('body', __('workflows.actions.fields.body'), __('workflows.actions.fields.body_hint')),
            WorkflowField::text('message_id', __('workflows.actions.fields.message'), __('workflows.actions.fields.message_hint'), required: false),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'message.id' => __('workflows.provides.message.id'),
            'thread.id' => __('workflows.provides.thread.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $parent = $this->message($context);
        $channel = $parent->channel;
        $body = trim((string) $context->setting('body', ''));

        if ($this->actor($context)->cannot('post', $channel)) {
            throw new RuntimeException(__('workflows.errors.may_not_post', ['channel' => $channel->name]));
        }

        if ($body === '') {
            throw new RuntimeException(__('workflows.errors.empty_message'));
        }

        /*
         * The parent goes in rather than being written on afterwards. That is
         * the whole difference between a reply and a message that merely has a
         * parent_id: the counter under the original, the thread's inbox entry
         * and the broadcast that puts it in an open thread all happen inside
         * post(), and all of them need to know at the moment the row is made.
         *
         * Pointed at the root of the thread rather than at whatever was
         * answered, so replies stay one level deep — a workflow must not be the
         * thing that invents a second.
         */
        $reply = $this->sendMessage->fromSystem(
            $channel,
            $body,
            $this->botName($context),
            $parent->parent_id ?? $parent->id,
        );

        return [
            'message' => ['id' => $reply->id],
            // The thread it landed in, so a following step can pin or react to
            // the conversation rather than to the reply that just joined it.
            'thread' => ['id' => $reply->parent_id],
        ];
    }
}
