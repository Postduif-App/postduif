<?php

namespace App\Policies;

use App\Models\Poll;
use App\Models\User;

class PollPolicy
{
    /**
     * Seeing a poll at all.
     *
     * Leans entirely on the channel, the way TicketPolicy and DocumentPolicy
     * do: a poll is exactly as visible as the room it was asked in, and
     * restating that here is how the two would eventually disagree.
     *
     * Written down rather than left implied because something now asks the
     * question of the poll rather than of the channel — a workflow step pointed
     * at one has a model and no room — and a policy with no answer denies,
     * which would have been a step failing for a reason nobody could read.
     */
    public function view(User $user, Poll $poll): bool
    {
        return $user->can('view', $poll->channel);
    }

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
