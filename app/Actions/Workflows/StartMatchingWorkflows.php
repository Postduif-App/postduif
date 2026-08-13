<?php

namespace App\Actions\Workflows;

use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\WorkflowTrigger;

/**
 * Everything that happens between a happening and a run.
 *
 * Find the workflows in this workspace listening for this trigger, drop the
 * ones this particular happening is not about, hand the rest to StartWorkflow.
 * Three sentences, written once.
 *
 * It sits here rather than in the listener base class because not every
 * listener is one listener for one trigger. The contracts listener answers to
 * eight events and belongs to eight triggers, and Laravel finds listeners by
 * the type of a handle method's first parameter — so it is one class with eight
 * of them, and it needs to say which trigger it means each time. A base class
 * whose trigger() took no arguments could not express that; a base class whose
 * trigger() took an event would push an unused parameter into the five
 * listeners that have only ever had one.
 *
 * So the loop is an action both shapes call, and StartsWorkflows is the thin
 * convenience for the ordinary case.
 */
class StartMatchingWorkflows
{
    public function __construct(
        private readonly StartWorkflow $startWorkflow,
        private readonly ResumeAwaitingWorkflows $resumeAwaiting,
    ) {}

    /**
     * @param  Workspace|null  $workspace  Null when the happening cannot say whose it was — a channel whose workspace has gone, in the window between a delete and the queue catching up. Nothing to start, and nothing worth throwing over.
     * @param  class-string<WorkflowTrigger>  $trigger
     * @param  callable(Workflow): (array<string, mixed>|null)  $contextFor  What the trigger saw, or null when this workflow was not waiting for this.
     * @param  array<string, mixed>|null  $happening  The same payload, as one thing rather than per workflow — what a run that is *waiting* for this needs to recognise itself in. Null where a happening carries no record anybody could sensibly wait for.
     */
    public function handle(?Workspace $workspace, string $trigger, callable $contextFor, ?array $happening = null): void
    {
        if ($workspace === null) {
            return;
        }

        /*
         * The feature check that used to live only in the builder, which a
         * listener never passes through: a workspace that has switched
         * contracts off should not have contract workflows going off in it.
         *
         * Asked once, of the workspace, rather than per workflow — availability
         * is a property of the workspace and every workflow found below belongs
         * to this one.
         */
        if (! $trigger::availableFor($workspace)) {
            return;
        }

        $workflows = Workflow::query()
            ->listeningFor($workspace, $trigger::key())
            ->get();

        foreach ($workflows as $workflow) {
            $context = $contextFor($workflow);

            /*
             * Filtered here rather than by writing the run down and finding out
             * later. In a busy workspace the keyword trigger is asked about
             * every message posted; a row per message that turns out to be
             * about the wrong channel is a table that fills up with nothing.
             */
            if ($context === null) {
                continue;
            }

            $this->startWorkflow->handle($workflow, $context);
        }

        /*
         * And the runs that were already going and stopped here to wait for
         * exactly this. Last, after the new ones are away, because the two are
         * independent and a workflow that both listens for a happening and
         * waits for it should see them in that order.
         *
         * Deliberately not behind the trigger's availableFor() check above —
         * it is, because it sits inside the same method, and that is right: a
         * workspace that switched contracts off has no business waking contract
         * workflows either.
         */
        if ($happening !== null) {
            $this->resumeAwaiting->handle($workspace, $trigger::key(), $happening);
        }
    }
}
