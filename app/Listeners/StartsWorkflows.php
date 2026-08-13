<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\WorkflowTrigger;

/**
 * The half of a trigger listener that is the same in every one of them.
 *
 * Ask the five listeners that came before this class what they do and they all
 * answer the same three sentences: find the workflows in this workspace that
 * listen for my trigger, drop the ones this particular happening is not about,
 * and hand the rest to StartWorkflow. Only the middle sentence differs, and it
 * differs in a way that is a handful of lines each time.
 *
 * That was liveable at five. The workflow builder is about to learn contracts,
 * tickets, documents and polls, which is another ten or so listeners, and ten
 * copies of a loop is ten places to forget the feature check or to write the
 * query without the index behind it.
 *
 * So a listener that extends this says four things: which trigger it belongs
 * to, where the workspace is in its event, whether a given workflow wanted this
 * one, and what the trigger saw. The last two are one method, on purpose —
 * see contextFor().
 *
 * The public handle() stays in the subclass, typed to its own event. Laravel
 * discovers listeners by reflecting on that parameter, and a base class that
 * declared handle(object $event) would leave every listener subscribed to
 * everything.
 *
 * @template TEvent of object
 */
abstract class StartsWorkflows
{
    public function __construct(protected readonly StartMatchingWorkflows $startWorkflows) {}

    /**
     * The trigger these workflows were written against.
     *
     * @return class-string<WorkflowTrigger>
     */
    abstract protected function trigger(): string;

    /**
     * Whose workflows to look at.
     *
     * Null when the event cannot say — a channel whose workspace has gone, in
     * the window between a delete and the queue catching up. Nothing to start,
     * and nothing worth throwing over either.
     *
     * @param  TEvent  $event
     */
    abstract protected function workspaceOf(object $event): ?Workspace;

    /**
     * What this workflow's trigger saw, or null when it was not about this.
     *
     * One method rather than a matches() and a data(), because for half these
     * triggers the two questions are the same question. The keyword listener
     * cannot answer "does this message concern you" without working out *which*
     * of the workflow's words it said, and that word is the thing the workflow
     * most wants to put in its reply. Split across two methods it would either
     * be worked out twice or remembered in a property, and a listener that
     * carries state between two workflows in a loop is a bug waiting for the
     * day two workflows watch the same word.
     *
     * The returned array is the contract with the trigger's provides(): a path
     * offered there and missing here is a variable that renders as nothing.
     *
     * @param  TEvent  $event
     * @return array<string, mixed>|null
     */
    abstract protected function contextFor(Workflow $workflow, object $event): ?array;

    /**
     * The three questions above, handed to the one loop there is.
     *
     * What that loop refuses, and why — including the feature check no listener
     * used to do for itself — is StartMatchingWorkflows.
     *
     * @param  TEvent  $event
     */
    protected function start(object $event): void
    {
        $this->startWorkflows->handle(
            $this->workspaceOf($event),
            $this->trigger(),
            fn (Workflow $workflow): ?array => $this->contextFor($workflow, $event),
        );
    }
}
