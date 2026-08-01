<?php

namespace App\Actions\Workspace;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RemoveWorkspaceMember
{
    /**
     * Take somebody out of a workspace, and out of everything inside it.
     *
     * Channel memberships have to go with it: leaving them behind would keep a
     * private channel listed for somebody who no longer belongs here at all.
     *
     * Channels they created are handed to the workspace owner rather than left
     * pointing at an outsider. A channel's creator cannot be removed from it
     * and cannot leave it, so an absent creator would freeze that channel's
     * membership for good.
     *
     * Their messages stay. They are part of conversations other people had.
     */
    public function handle(Workspace $workspace, User $member): void
    {
        DB::transaction(function () use ($workspace, $member) {
            $channelIds = $workspace->channels()->pluck('id');

            Channel::whereIn('id', $channelIds)
                ->where('created_by', $member->id)
                ->update(['created_by' => $workspace->owner_id]);

            DB::table('channel_user')
                ->whereIn('channel_id', $channelIds)
                ->where('user_id', $member->id)
                ->delete();

            $workspace->members()->detach($member->id);
        });
    }
}
