<?php

namespace App\Workflows;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\Workspace;

/**
 * Everything one step is allowed to know while it runs.
 *
 * Handed to the action rather than letting it reach for the run itself, and
 * that is the point of the class: an action that could load the run could also
 * write to it, and then "what is in the context" would be answerable only by
 * reading every action.
 *
 * The configuration arrives resolved — every {{ ... }} already replaced — so no
 * action has to know that variables exist at all.
 */
final class WorkflowStepContext
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public readonly Workflow $workflow,
        public readonly WorkflowRun $run,
        public readonly array $config,
    ) {}

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /** Read something the trigger or an earlier step put in the run's memory. */
    public function value(string $path, mixed $default = null): mixed
    {
        return data_get($this->run->context, $path, $default);
    }

    /**
     * Whose rights this step runs with.
     *
     * The workflow's owner, never the person who set the trigger off. A guest
     * who reacts with an emoji would otherwise be the one archiving a channel,
     * and every permission check downstream would be asking the wrong person.
     *
     * Null when the account is gone, which the runner treats as a reason to
     * stop rather than as nobody in particular.
     */
    public function actor(): ?User
    {
        return $this->workflow->owner;
    }

    public function workspace(): Workspace
    {
        return $this->workflow->workspace;
    }
}
