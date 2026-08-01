<?php

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The tickets that have been left sitting.
 *
 * Two kinds, and they are genuinely different problems: one is late against a
 * date somebody promised, the other never got an answer at all. The second is
 * the one a customer notices first, and the one no due date would have caught
 * because nobody got round to setting one.
 */
class FindStaleTickets
{
    /**
     * How long an unanswered ticket may sit before it counts as neglected.
     *
     * A day rather than a few hours: a ticket raised at five in the afternoon
     * should not be a failure by seven, and anything shorter turns the reminder
     * into something that fires during every normal evening.
     */
    public const SILENCE_HOURS = 24;

    /**
     * How long between two reminders about the same ticket. Nagging hourly is
     * how people learn to ignore the reminder altogether.
     */
    public const COOLDOWN_HOURS = 24;

    /**
     * @return Collection<int, Ticket>
     */
    public function handle(): Collection
    {
        return Ticket::query()
            ->open()
            // A ticket that waits on the customer is not one anybody forgot, so
            // it is not something to nag the channel about.
            ->whereNot('status', TicketStatus::Waiting->value)
            ->with(['channel.workspace', 'assignee'])
            ->where(fn (Builder $query) => $query
                ->whereNull('reminded_at')
                ->orWhere('reminded_at', '<', now()->subHours(self::COOLDOWN_HOURS)))
            ->where(fn (Builder $query) => $query
                // Late against a date somebody set.
                ->where(fn (Builder $overdue) => $overdue
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now()))
                // Or never answered at all — the one a customer notices first,
                // and the one no due date would have caught because nobody got
                // round to setting one.
                ->orWhere(fn (Builder $silent) => $silent
                    ->whereNull('first_responded_at')
                    ->where('created_at', '<', now()->subHours(self::SILENCE_HOURS))))
            ->get()
            // Only where the channel still keeps tickets. A channel that stopped
            // should not keep sending reminders about work nobody there can see
            // any more.
            ->filter(fn (Ticket $ticket) => $ticket->channel->hasTickets())
            ->values();
    }
}
