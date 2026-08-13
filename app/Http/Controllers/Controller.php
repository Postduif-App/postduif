<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Workspace;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Refuse a channel that has no business being reached from this workspace.
     *
     * Every endpoint under /app/{workspace}/c/{channel} needs this, because the
     * channel is bound by id alone: without it, any member of any workspace
     * could name any channel in the world and let the policy be the only thing
     * standing in the way. Two gates rather than one, deliberately — the
     * policy answers "may this person", and this answers "does this thing exist
     * here", which is the question a 404 is for.
     *
     * It used to be a comparison each controller wrote out, and it stopped
     * being one the day a channel could belong to two workspaces at once: a
     * channel another workspace has opened to this one is reachable from here,
     * and every endpoint had to learn that on the same day or the feature would
     * be half-built — a shared channel you could read but not reply in, and no
     * pattern to say which half was which.
     *
     * The extra query only happens for a channel that is not this workspace's
     * own, which for most installations is never.
     */
    protected function channelIsReachable(Workspace $workspace, Channel $channel): void
    {
        if ($channel->workspace_id === $workspace->id) {
            return;
        }

        abort_unless(
            $channel->shares()->live()->where('workspace_id', $workspace->id)->exists(),
            404,
        );
    }
}
