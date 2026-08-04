<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowRunStatus;
use App\Jobs\RunWorkflowJob;
use App\Models\WorkflowRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pick up the runs whose waiting is over.
 *
 * The same shape as the scheduled messages, and for the same reasons: claimed
 * before anything is done with them, so a second sweep starting while this one
 * is halfway finds nothing left to take — with or without withoutOverlapping on
 * the schedule.
 *
 * Claiming here means moving the run back to Running. That is enough: the due
 * scope only looks at waiting runs, so a claimed one is invisible to the next
 * sweep, and the runner itself refuses anything that is not open.
 */
class ResumeWaitingWorkflows
{
    /**
     * @return int How many runs were sent back to a worker.
     */
    public function handle(): int
    {
        $due = $this->claimDue();

        foreach ($due as $run) {
            RunWorkflowJob::dispatch($run->id);
        }

        return $due->count();
    }

    /**
     * @return Collection<int, WorkflowRun>
     */
    private function claimDue(): Collection
    {
        return DB::transaction(function (): Collection {
            $due = WorkflowRun::query()
                ->due()
                ->lockForUpdate()
                ->get();

            /*
             * resume_at is cleared along with the status. Leaving it would make
             * a run that fails later look as though it were still due, and the
             * only thing reading that column is the sweep this line protects.
             */
            WorkflowRun::whereKey($due->pluck('id'))->update([
                'status' => WorkflowRunStatus::Running,
                'resume_at' => null,
            ]);

            return $due;
        });
    }
}
