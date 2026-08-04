<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\ToggleReaction;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;

/**
 * Take an emoji back off a message.
 *
 * Only ever the owner's own reaction — there is no removing somebody else's,
 * here or anywhere in the application. A workflow that could would be a way to
 * quietly undo other people's votes on a poll of emoji.
 */
class RemoveReaction extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly ToggleReaction $reactions) {}

    public static function label(): string
    {
        return __('workflows.actions.remove-reaction.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.remove-reaction.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::emoji('emoji', __('workflows.actions.fields.emoji')),
            WorkflowField::text('message_id', __('workflows.actions.fields.message'), __('workflows.actions.fields.message_hint'), required: false),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $message = $this->message($context);

        $this->reactions->remove($message, $this->actor($context), (string) $context->setting('emoji'));

        return ['message' => ['id' => $message->id]];
    }
}
