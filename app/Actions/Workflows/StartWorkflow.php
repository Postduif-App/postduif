<?php

namespace App\Actions\Workflows;

use App\Enums\WorkflowRunStatus;
use App\Features\Workflows as WorkflowsFeature;
use App\Jobs\RunWorkflowJob;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Workflows\WorkflowDepth;

/**
 * Set a workflow going.
 *
 * The one door in. Every trigger — a listener, a webhook route, the scheduler,
 * somebody choosing it from a message menu — comes through here, so there is
 * one place where "may this run at all" is decided and one place where the run
 * is written down.
 *
 * The work itself goes on the queue. Somebody who posts a message that sets
 * three workflows off should not be waiting for those three, and a workflow
 * that fails must not be able to fail the thing that triggered it.
 */
class StartWorkflow
{
    /**
     * @param  array<string, mixed>  $triggerData  What the trigger saw, as its provides() describes it.
     * @return WorkflowRun|null The run, or null when there was reason not to start one.
     */
    public function handle(Workflow $workflow, array $triggerData = []): ?WorkflowRun
    {
        if (! $this->mayStart($workflow)) {
            return null;
        }

        $run = WorkflowRun::create([
            'workflow_id' => $workflow->id,
            'status' => WorkflowRunStatus::Running,

            /*
             * Everything the trigger saw goes in under one name, so a step can
             * only ever read {{ trigger.something }} and never collide with
             * what an earlier step filed under steps.
             *
             * The depth travels with the run because the queue is a fresh
             * process that would otherwise start counting at zero — which is
             * exactly how a loop gets past an in-process guard.
             */
            'context' => [
                'trigger' => $triggerData,
                'depth' => WorkflowDepth::current() + 1,
            ],
        ]);

        RunWorkflowJob::dispatch($run->id);

        return $run;
    }

    /**
     * Whether this workflow should run at all right now.
     *
     * Three separate refusals, and each is a different kind of "no":
     *
     * The workspace may have switched workflows off since this one was written,
     * which has to be checked here rather than only in the builder — a
     * listener does not go through the builder.
     *
     * The workflow may be switched off, which the listener's own query already
     * excludes; asked again because not every trigger is a listener.
     *
     * And it may have no owner left, which is the one worth being strict about:
     * the steps run with the owner's rights, and a workflow with nobody behind
     * it would either run as nobody or, worse, skip the permission checks
     * entirely.
     */
    private function mayStart(Workflow $workflow): bool
    {
        if (! WorkflowDepth::hasRoom()) {
            return false;
        }

        if (! $workflow->isEnabled() || $workflow->owner === null) {
            return false;
        }

        return $workflow->workspace?->hasFeature(WorkflowsFeature::class) ?? false;
    }
}
