<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody said something on a ticket.
 *
 * Its own event rather than a case in TicketChanged, for the reason the
 * timeline keeps the two apart: a comment can be edited and withdrawn, an event
 * is what happened and stays. Folding them together would put a kind of entry
 * in TicketEventType that never appears in a timeline row.
 *
 * It carries whether this was the first answer the ticket got, which is the one
 * thing about a comment that is worth a workflow's attention: "de klant kreeg
 * eindelijk antwoord" is a different event from the fifteenth message in a
 * thread, and nothing downstream could work it out afterwards — by then
 * first_responded_at is set either way.
 */
class TicketCommented implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $commentId,
        public readonly int $authorId,
        public readonly bool $wasFirstResponse = false,
    ) {}
}
