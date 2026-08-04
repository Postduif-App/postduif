<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workflow;

/**
 * Who may look at and change one particular workflow.
 *
 * Every answer here is the workspace's answer — see
 * WorkspacePolicy::manageWorkflows — with one thing added that the workspace
 * cannot check: that this workflow belongs to the workspace being asked about.
 * Without that, an id from another workspace in a URL would be judged by the
 * rights the visitor has in their own.
 */
class WorkflowPolicy
{
    public function view(User $user, Workflow $workflow): bool
    {
        return $this->manages($user, $workflow);
    }

    public function update(User $user, Workflow $workflow): bool
    {
        return $this->manages($user, $workflow);
    }

    public function delete(User $user, Workflow $workflow): bool
    {
        return $this->manages($user, $workflow);
    }

    private function manages(User $user, Workflow $workflow): bool
    {
        $workspace = $workflow->workspace;

        return $workspace !== null && $user->can('manageWorkflows', $workspace);
    }
}
