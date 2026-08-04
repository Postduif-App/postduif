<?php

namespace App\Workflows\Actions;

use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowPaused;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Wait, and go on afterwards.
 *
 * Not with a sleep. The run is on a queue, and a worker that sits still for an
 * hour is a worker doing nothing for an hour — with a handful of these in a
 * workspace that is the whole queue gone. Instead the run is put down: its
 * position and its memory are already in the database, so picking it up again
 * is nothing more than starting the same walk from a later step.
 *
 * What that costs is worth being explicit about. Between the pause and the
 * resumption the workflow can be switched off, a channel can be archived and
 * the owner's account can disappear — so everything the runner checks at the
 * start it checks again on the way back in, and a run that finds the ground has
 * shifted stops rather than stumbles.
 */
class Delay extends WorkflowAction
{
    /**
     * Four weeks, which is a limit on how wrong a mistake can be rather than on
     * what anybody sensibly wants. A workflow set to wait a year is one nobody
     * will be around to remember writing, and the row would sit in the table
     * being swept over every minute until then.
     */
    public const MAX_MINUTES = 40320;

    public static function label(): string
    {
        return __('workflows.actions.delay.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.delay.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::number('minutes', __('workflows.actions.delay.minutes.label'), __('workflows.actions.delay.minutes.hint')),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $minutes = (int) $context->setting('minutes', 0);

        /*
         * Nought minutes is not a wait. Refused rather than passed over,
         * because a step that does nothing is a step somebody meant to fill in
         * — and passing it silently would leave a workflow that reads as though
         * it waits and does not.
         */
        if ($minutes < 1) {
            throw new RuntimeException(__('workflows.errors.delay_too_short'));
        }

        if ($minutes > self::MAX_MINUTES) {
            throw new RuntimeException(__('workflows.errors.delay_too_long'));
        }

        throw new WorkflowPaused(now()->addMinutes($minutes));
    }
}
