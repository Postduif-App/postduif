<?php

namespace App\Actions\Tickets;

use App\Events\TicketUpdated;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Takes a ticket off the board.
 *
 * A soft delete, like everything else here. What gets deleted is nearly always
 * a mistake — a duplicate, a test, something raised in the wrong channel — and
 * the one case that is not is the one where somebody will ask for it back.
 *
 * The comments are left exactly as they are. Their own soft delete already
 * means something else on a ticket: a withdrawn comment is a tombstone in the
 * timeline, so deleting them here would come back, if the ticket ever does, as
 * a support history where everybody withdrew everything they ever said. Nothing
 * reads them while the ticket is gone anyway — every query for a comment goes
 * through the ticket.
 *
 * Deliberately quiet in the channel: AnnounceTicket says a ticket was opened
 * and that it was closed, both of which are news to the people reading along. A
 * ticket that turned out not to exist is not, and a bot message about it would
 * outlive the thing it is about.
 */
class DeleteTicket
{
    public function handle(Ticket $ticket): void
    {
        DB::transaction(function () use ($ticket): void {
            $ticket->delete();

            /*
             * The same event every other change dispatches. Boards do not patch
             * a ticket into place from a payload, they ask for their props
             * again — and a board that asks again is a board this ticket has
             * quietly dropped off, which is exactly right.
             */
            TicketUpdated::dispatch($ticket);
        });
    }
}
