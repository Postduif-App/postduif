<?php

namespace App\Actions\SharedChannels;

use App\Models\ChannelShare;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

class AddSharedChannelMembers
{
    /**
     * Put people from the invited workspace into the shared channel.
     *
     * The guest side's only way in, and the reason ChannelPolicy::addMembers
     * refuses outsiders: that button searches the *host's* member directory,
     * which is not something an outside participant should be handed. This one
     * can only ever reach the workspace the share belongs to.
     *
     * Ids that name somebody outside that workspace are dropped rather than
     * refused. The list comes from a picker that only offers colleagues, so a
     * stranger's id in it is somebody probing the endpoint — and the useful
     * answer to that is the four people who were legitimate going in, not an
     * error that tells them which id was the interesting one.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, User> the people actually added
     *
     * @throws RuntimeException when the arrangement is not live
     */
    public function handle(ChannelShare $share, array $userIds): Collection
    {
        if (! $share->isLive()) {
            throw new RuntimeException('This channel is not shared with that workspace.');
        }

        /** @var Collection<int, User> $candidates */
        $candidates = $share->workspace->members()
            ->whereIn('users.id', $userIds)
            // Already in the channel, whether through this share or because
            // they happen to belong to the host workspace as well. Attaching
            // them again would move their joined_at and, with it, the mark the
            // unread count is measured from.
            ->whereNotIn('users.id', $share->channel->members()->select('users.id'))
            ->get();

        foreach ($candidates as $member) {
            $share->channel->members()->attach($member->id, ['joined_at' => now()]);
        }

        return $candidates;
    }
}
