<?php

namespace App\Jobs;

use App\Actions\Workflows\RunWorkflow;
use App\Models\WorkflowRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Walk a run through its steps, away from whatever set it off.
 *
 * On a queue because a workflow must not be able to slow down or break the
 * thing that triggered it: somebody posting a message that trips three
 * workflows is posting a message, not running three workflows.
 *
 * Carries the run's id rather than the model, so what the job picks up is the
 * row as it stands when a worker gets to it — which for a run that has been
 * waiting an hour is not the row that was serialised.
 */
class RunWorkflowJob implements ShouldQueue
{
    use Queueable;

    /**
     * Once.
     *
     * Steps are not undoable — a message posted twice cannot be taken back —
     * so a retry after a half-finished run would repeat everything up to the
     * point it broke. What a failed run gets instead is a reason on the row and
     * a line per step, which is what somebody needs in order to decide whether
     * to run it again on purpose.
     */
    public int $tries = 1;

    public function __construct(public readonly int $runId) {}

    public function handle(RunWorkflow $runWorkflow): void
    {
        $run = WorkflowRun::query()->whereKey($this->runId)->first();

        // Gone, because the workflow or the workspace was deleted while this
        // sat in the queue. Nothing left to do and nothing worth saying.
        if ($run === null) {
            return;
        }

        $runWorkflow->handle($run);
    }
}
