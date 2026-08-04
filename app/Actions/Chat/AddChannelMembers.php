<?php

namespace App\Actions\Chat;

use App\Events\ChannelMemberJoined;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Collection;

class AddChannelMembers
{
    /**
     * Add workspace members to a channel and return the ones actually added.
     *
     * The candidate list is re-derived from the workspace here rather than
     * trusted from the request. Ids arrive from a browser, and a private
     * channel's membership is exactly the kind of thing worth checking twice.
     *
     * @param  Collection<int, int>|array<int, int>  $userIds
     * @return Collection<int, User>
     */
    public function handle(Channel $channel, Collection|array $userIds): Collection
    {
        $wanted = (new Collection($userIds))->map(fn ($id) => (int) $id)->unique();

        if ($wanted->isEmpty()) {
            return new Collection;
        }

        $alreadyIn = $channel->members()->pluck('users.id');

        $toAdd = $channel->workspace->members()
            ->whereIn('users.id', $wanted->diff($alreadyIn))
            ->get();

        foreach ($toAdd as $user) {
            $channel->members()->attach($user->id, ['joined_at' => now()]);

            // Said out loud so a workflow can hang a welcome off it. Inside the
            // loop rather than after it: one arrival is one event, and a
            // workflow that greeted four people in one message would be reading
            // a list nobody wrote.
            ChannelMemberJoined::dispatch($channel, $user);
        }

        return $toAdd;
    }
}
