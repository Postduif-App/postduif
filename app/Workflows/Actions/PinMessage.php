<?php

namespace App\Workflows\Actions;

use App\Events\MessagePinned;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Put a message at the top of its channel.
 *
 * Pinned in the owner's name, because a pin records who put it up and there is
 * no bot to record. That is the honest answer too: the owner is the one who
 * decided this should be pinned, they just decided it in advance.
 */
class PinMessage extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.pin-message.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.pin-message.description');
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
        $actor = $this->actor($context);

        if ($actor->cannot('pin', $message)) {
            throw new RuntimeException(__('workflows.errors.may_not_pin'));
        }

        /*
         * The ceiling the pin screen enforces is deliberately not repeated
         * here. A workflow that hits it should not fail the run over it — the
         * message is posted, the pin is a flourish — and Message::pin() already
         * leaves an existing pin alone, so running twice does nothing twice.
         */
        $message->pin($actor);

        MessagePinned::dispatch($message);

        return ['message' => ['id' => $message->id]];
    }
}
