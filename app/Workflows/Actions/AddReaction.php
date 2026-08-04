<?php

namespace App\Workflows\Actions;

use App\Actions\Chat\ToggleReaction;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;

/**
 * Put an emoji on a message.
 *
 * In the owner's name, because a reaction belongs to somebody: the list under a
 * message is a list of people, and there is no bot to put in it. A workflow
 * that reacts is the owner reacting on a rule they wrote earlier.
 *
 * Worth knowing when this is combined with the emoji trigger: a workflow that
 * reacts with the emoji it listens for is a loop, and what stops it is the
 * depth guard rather than anything here.
 */
class AddReaction extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly ToggleReaction $reactions) {}

    public static function label(): string
    {
        return __('workflows.actions.add-reaction.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.add-reaction.description');
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

        $this->reactions->add($message, $this->actor($context), (string) $context->setting('emoji'));

        return ['message' => ['id' => $message->id]];
    }
}
