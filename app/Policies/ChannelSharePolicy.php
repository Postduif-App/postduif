<?php

namespace App\Policies;

use App\Models\ChannelShare;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who may look at an arrangement between two workspaces, and who may end it.
 *
 * Written down as a policy once a workflow could be pointed at a share. Until
 * then the rule lived in the controller, where the workspace in the URL decided
 * which of the two questions to ask; a workflow has no URL, so the rule had to
 * become something both callers could ask.
 *
 * Two sides and never one. A share is a room the host opened and the guest
 * walked into, and each of them may close their own half of it — the host
 * because it is their channel, the guest because they answer for the people who
 * came in through it.
 */
class ChannelSharePolicy
{
    /**
     * Whether this person is on either side of the arrangement.
     *
     * Deliberately the union of the two questions below, because that is what
     * "may you look at this" means: it is the same row from both ends. Which
     * side somebody is standing on is a question about ending it, not about
     * seeing it — and every caller that acts on a share asks sever() as well.
     */
    public function view(User $user, ChannelShare $share): bool
    {
        return $user->can('manage', $share->workspace)
            || $user->can('manageSettings', $share->channel);
    }

    /**
     * Whether this person may end it from the workspace they are acting in.
     *
     * The workspace is an argument rather than something worked out from the
     * user, and that is the whole of the rule: a host must not be able to reach
     * this by way of the guest's side or the other way round. The same row, but
     * a different right — conflating them would let anybody who may manage any
     * workspace end any share.
     */
    public function sever(User $user, ChannelShare $share, Workspace $workspace): bool
    {
        if ($share->workspace_id === $workspace->id) {
            return $user->can('manage', $workspace);
        }

        if ($share->channel->workspace_id === $workspace->id) {
            return $user->can('manageSettings', $share->channel);
        }

        return false;
    }
}
