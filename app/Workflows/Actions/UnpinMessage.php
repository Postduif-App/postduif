<?php

namespace App\Workflows\Actions;

use App\Events\MessagePinned;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Take a message back down again.
 *
 * The other half of pinning, and the reason both exist as separate actions
 * rather than one that toggles: a workflow is written once and runs many times,
 * so "pin this" has to mean pin this every time, not "pin it, then unpin it,
 * then pin it again".
 */
class UnpinMessage extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.unpin-message.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.unpin-message.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::text('message_id', __('workflows.actions.fields.message'), __('workflows.actions.fields.message_hint'), required: false),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $message = $this->message($context);

        if ($this->actor($context)->cannot('pin', $message)) {
            throw new RuntimeException(__('workflows.errors.may_not_pin'));
        }

        $message->unpin();

        MessagePinned::dispatch($message);

        return ['message' => ['id' => $message->id]];
    }
}
