<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\Huddle;
use App\Models\User;

/**
 * Who may talk in a channel, and who may listen in.
 *
 * Leans on the ChannelPolicy for both, the same way TicketPolicy does: a huddle
 * is exactly as reachable as the channel it is held in, and a second set of
 * rules here is how the two would eventually disagree.
 */
class HuddlePolicy
{
    /**
     * Starting one, or coming into the one that is going.
     *
     * Posting rather than viewing, which is the stricter of the two. Somebody
     * who may read along in a public channel but not say anything in it is
     * being kept out of the conversation on purpose, and a huddle is the
     * conversation — with the added detail that joining one puts your voice in
     * front of everybody already in it.
     *
     * The channel has to be a live one: an archived channel is a record, and
     * you cannot hold a meeting inside a record.
     */
    public function join(User $user, Channel $channel): bool
    {
        return $channel->archived_at === null
            && $user->can('post', $channel);
    }

    /**
     * Seeing that a huddle is going on, and who is in it.
     *
     * Wider than joining, deliberately: knowing that four colleagues are
     * talking in #support is the thing that makes somebody walk in, and hiding
     * it from a reader who could have joined by posting one message first would
     * hide the feature from the people it is for.
     */
    public function see(User $user, Channel $channel): bool
    {
        return $user->can('view', $channel);
    }

    /**
     * Taking yourself out of one. Your own only — see LeaveHuddle for why
     * nobody gets a button that ends a huddle for everybody else.
     */
    public function leave(User $user, Huddle $huddle): bool
    {
        return $huddle->present()->where('user_id', $user->id)->exists();
    }
}
