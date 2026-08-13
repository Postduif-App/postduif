<?php

namespace App\Actions\SharedChannels;

use App\Events\ChannelShareRevoked;
use App\Models\ChannelShare;
use Illuminate\Support\Facades\DB;

class RevokeChannelShare
{
    /**
     * End the arrangement, from whichever side ended it.
     *
     * The row alone would be enough to shut the door — every policy asks
     * whether the share is live before letting an outsider read or write — but
     * the memberships are taken out too, and deliberately so. A channel whose
     * member list still names ten people from a company that no longer has
     * access is a list that lies to everybody reading it, and it is the list
     * the people inside use to decide what they can say here.
     *
     * What is not removed is anything already said. Their messages stay, with
     * their names on them: a shared channel is a conversation two organisations
     * had, and deleting one side of it when the arrangement ends would rewrite
     * the other side's history as well.
     */
    public function handle(ChannelShare $share): ChannelShare
    {
        return DB::transaction(function () use ($share): ChannelShare {
            $share->forceFill(['revoked_at' => now()])->save();

            /*
             * Everybody in the channel who came in through this arrangement.
             *
             * Note the second condition, which is the one that is easy to
             * forget: somebody can belong to both workspaces — a contractor,
             * an owner with two teams — and for them the share was never what
             * granted access. Detaching them along with the rest would take a
             * channel away from somebody who was always entitled to it, and
             * from the host's own side of the room.
             */
            $leaving = $share->workspace->members()
                ->whereIn('users.id', $share->channel->members()->select('users.id'))
                ->whereNotIn('users.id', $share->channel->workspace->members()->select('users.id'))
                ->pluck('users.id');

            $share->channel->members()->detach($leaving);

            /*
             * After the detaching, so anything acting on this finds the room
             * already emptied of the guests rather than racing it.
             */
            ChannelShareRevoked::dispatch($share->id);

            return $share;
        });
    }
}
