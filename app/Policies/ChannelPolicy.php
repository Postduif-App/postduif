<?php

namespace App\Policies;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\User;

class ChannelPolicy
{
    /**
     * Public channels are readable by every workspace member; private channels
     * and DMs only by their explicit members.
     */
    public function view(User $user, Channel $channel): bool
    {
        if (! $channel->workspace->hasMember($user)) {
            return false;
        }

        if ($channel->type === ChannelType::Public) {
            return true;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Posting always requires membership, even in a public channel: reading is
     * open, writing means you joined.
     */
    public function post(User $user, Channel $channel): bool
    {
        if ($channel->archived_at !== null) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    public function join(User $user, Channel $channel): bool
    {
        return $channel->type === ChannelType::Public
            && $channel->archived_at === null
            && $channel->workspace->hasMember($user);
    }
}
