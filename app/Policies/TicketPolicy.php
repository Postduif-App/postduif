<?php

namespace App\Policies;

use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Moderation from the admin panel, the same split ChannelPolicy makes: a
     * platform moderator may act on tickets there without that quietly becoming
     * read access to every customer channel in the chat UI.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /**
     * Seeing the tickets of a channel at all.
     *
     * Leans entirely on the channel: a ticket is exactly as visible as the
     * channel it sits in, and restating the rule here is how the two would
     * eventually disagree. Note this does not ask the ticket policy — a channel
     * that stopped keeping tickets still has to show the ones already raised,
     * or switching the setting off would silently hide open work.
     */
    public function viewBoard(User $user, Channel $channel): bool
    {
        return $channel->hasTickets()
            && app(ChannelPolicy::class)->view($user, $channel);
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return $this->viewBoard($user, $ticket->channel);
    }

    /**
     * Opening a ticket in a channel.
     *
     * Membership is the floor, the same as posting: reading a public channel is
     * open, adding work to it means you joined. On top of that sits the
     * channel's own ticket policy, which decides whether guests count.
     */
    public function create(User $user, Channel $channel): bool
    {
        if (! $this->viewBoard($user, $channel) || $channel->archived_at !== null) {
            return false;
        }

        if (! $channel->members()->whereKey($user->id)->exists()) {
            return false;
        }

        return $channel->ticket_policy->allowsOpening($channel, $user);
    }

    /**
     * Saying something on a ticket.
     *
     * Open to everyone who can see it, guests included and regardless of the
     * ticket policy: a customer who may raise a ticket but not answer questions
     * about it leaves every ticket stuck at the first reply.
     */
    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket)
            && $ticket->channel->archived_at === null
            && $ticket->channel->members()->whereKey($user->id)->exists();
    }

    /**
     * Status, priority, assignee, due date — everything that says how the work
     * is being handled rather than what it is.
     *
     * Not for guests. A customer marking their own ticket urgent and in progress
     * tells nobody anything; these fields only mean something when one side
     * weighs all the tickets against each other. What a customer does get is
     * confirm() below.
     */
    public function manage(User $user, Ticket $ticket): bool
    {
        if (! $this->comment($user, $ticket)) {
            return false;
        }

        return ! ($ticket->channel->workspace->roleFor($user)?->isGuest() ?? true);
    }

    /**
     * Accepting that a resolved ticket is really done, or saying that it is not.
     *
     * The one status move that belongs to whoever raised the ticket: they are
     * the only one who can tell whether their problem is actually gone. Anyone
     * who may manage the ticket can do it too, for the customer who never
     * answers.
     */
    public function confirm(User $user, Ticket $ticket): bool
    {
        if (! $this->comment($user, $ticket)) {
            return false;
        }

        return $ticket->opened_by === $user->id || $this->manage($user, $ticket);
    }

    /**
     * Correcting the title or description after the fact.
     *
     * The author's own, plus whoever manages the channel's tickets — a
     * one-line "het werkt niet" is worth rewriting into something findable, and
     * the person who wrote it is usually not the one who notices.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        if (! $this->comment($user, $ticket)) {
            return false;
        }

        return $ticket->opened_by === $user->id || $this->manage($user, $ticket);
    }
}
