<?php

namespace App\Policies;

use App\Models\Poll;
use App\Models\User;

class PollPolicy
{
    /**
     * Voting.
     *
     * Anybody who can see the channel, guests included — they are in it, and
     * the question is as much about them. A closed poll takes nothing from
     * anybody, which is why that check lives here rather than in the controller:
     * it is the same answer for every route that would change a vote.
     */
    public function vote(User $user, Poll $poll): bool
    {
        return ! $poll->isClosed() && $user->can('view', $poll->channel);
    }

    /**
     * Closing it early.
     *
     * The person who asked, or whoever manages the channel. Unlike a secret
     * request — where an admin is deliberately kept out — there is nothing
     * private here to protect: closing a poll only stops it, and a question
     * left open in a channel after the moment has passed is the channel's
     * problem to tidy.
     */
    public function close(User $user, Poll $poll): bool
    {
        if ($poll->created_by === $user->id) {
            return true;
        }

        return $user->can('manageSettings', $poll->channel);
    }

    /**
     * Opening it again.
     *
     * The same people as closing it: whoever could stop the question can also
     * decide it was stopped too early. Splitting the two would mean an asker
     * who closed a poll by accident has to find somebody else to undo it.
     */
    public function reopen(User $user, Poll $poll): bool
    {
        return $this->close($user, $poll);
    }
}
