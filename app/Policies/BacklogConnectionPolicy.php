<?php

namespace App\Policies;

use App\Models\BacklogConnection;
use App\Models\User;

/**
 * Seeing one workspace's arrangement with Backlog.
 *
 * Two different questions wear the same word here. Configuring a connection —
 * its secrets, its URL — is a platform moderator's job: that screen lives
 * under BacklogConnectionsRelationManager, in the /admin panel, gated by
 * isAdmin(). Pointing a workflow step at one already-provisioned connection is
 * an ordinary workspace decision, the same authority WorkflowPolicy already
 * asks for building the workflow in the first place — see manageWorkflows on
 * Workspace. This policy answers the second question: whoever could have
 * built the workflow may name a connection it points at, nothing more.
 */
class BacklogConnectionPolicy
{
    public function view(User $user, BacklogConnection $connection): bool
    {
        return $user->can('manageWorkflows', $connection->workspace);
    }
}
