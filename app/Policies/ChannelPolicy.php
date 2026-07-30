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

    /**
     * Anyone already inside a channel may bring someone else in — the same rule
     * Slack uses, and the only one that works without an owner concept.
     *
     * A DM is excluded: adding a third person to a two-person conversation
     * would silently change what everyone in it thought they were writing in.
     */
    public function addMembers(User $user, Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->archived_at !== null) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Leaving is allowed, with two exceptions.
     *
     * A DM has no meaning with one participant left in it. And the channel's
     * creator cannot walk out: they are the only member with a claim to it, so
     * their leaving would strand a private channel with nobody responsible for
     * who gets in. Hand it over first — once ownership can be transferred, this
     * is the check that should learn about it.
     */
    public function leave(User $user, Channel $channel): bool
    {
        if ($channel->isDirect() || $channel->created_by === $user->id) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Removing someone else follows the same rule as adding them: if you are in
     * the channel, you can manage who else is. The creator is exempt for the
     * same reason they cannot leave.
     */
    public function removeMember(User $user, Channel $channel, User $target): bool
    {
        if ($channel->isDirect() || $channel->archived_at !== null) {
            return false;
        }

        if ($channel->created_by === $target->id) {
            return false;
        }

        return $channel->members()->whereKey($user->id)->exists()
            && $channel->members()->whereKey($target->id)->exists();
    }
}
