<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Events\PollClosed;
use App\Features\Polls;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Stop a poll.
 *
 * What it is for is the poll that has already been answered: a delay of a day
 * and this step is "je hebt tot morgen", said once and enforced without anybody
 * watching the clock. Together with the vote trigger it is also "sluit zodra
 * tien mensen hetzelfde kozen", which is a decision a channel would otherwise
 * argue about.
 *
 * Recorded as closed_at rather than by moving the deadline, the same as the
 * button in the channel: the card can then say somebody stopped this, which
 * reads differently from a moment that passed.
 *
 * Closing an already closed poll does nothing and says so quietly — a workflow
 * that fires twice should not fail the second time for having got what it
 * wanted.
 */
class ClosePoll extends WorkflowAction
{
    use FindsTargets;

    public static function label(): string
    {
        return __('workflows.actions.close-poll.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.close-poll.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'poll_id',
                WorkflowRecordType::Poll,
                __('workflows.actions.fields.poll'),
                __('workflows.actions.fields.poll_hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'poll.id' => __('workflows.provides.poll.id'),
            'poll.question' => __('workflows.provides.poll.question'),
            'poll.is_closed' => __('workflows.provides.poll.is_closed'),
            'closed' => __('workflows.provides.poll.closed_now'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Polls::class)) {
            throw new RuntimeException(__('workflows.errors.polls_off'));
        }

        $poll = $this->poll($context);

        if ($this->actor($context)->cannot('close', $poll)) {
            throw new RuntimeException(__('workflows.errors.may_not_close_poll'));
        }

        $closed = $poll->closed_at === null;

        if ($closed) {
            $poll->forceFill(['closed_at' => now()])->save();

            // The same event the button in the channel fires, so a workflow
            // that reports results can hang off either.
            PollClosed::dispatch($poll->id);
        }

        return [
            'poll' => [
                'id' => $poll->id,
                'question' => $poll->question,
                'is_closed' => true,
            ],
            // False when it was already shut, which is not a failure — see the
            // class note.
            'closed' => $closed,
        ];
    }
}
