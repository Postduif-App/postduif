<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowRunStatus;
use App\Models\WorkflowRun;

/**
 * Clear out the runs nobody is going to read again.
 *
 * Runs hold the context as it stood, which means message text and people's
 * names — see the migration. Keeping that forever would turn a debugging aid
 * into an archive of everything every workflow ever saw, so it goes the way the
 * transfers and the secrets already do.
 */
class PruneWorkflowRuns
{
    /**
     * Two weeks, and the number is about what the screen is for: somebody
     * investigating "waarom gebeurde er niets" is looking at this week, not at
     * March. Long enough to cover a holiday, short enough that the table does
     * not become a record of the workspace's conversations.
     */
    public const KEEP_DAYS = 14;

    public function handle(): int
    {
        /*
         * Finished ones only. A run that is still waiting has a moment ahead of
         * it — a delay may be a week long — and clearing it would leave a
         * workflow permanently half-done with nothing to say why.
         */
        return WorkflowRun::query()
            ->whereIn('status', [WorkflowRunStatus::Succeeded, WorkflowRunStatus::Failed])
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->delete();
    }
}
