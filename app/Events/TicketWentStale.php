<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket has been left sitting long enough to be worth saying so.
 *
 * Fired by the nightly sweep, once per ticket it found, and bounded by the same
 * cooldown as the mail it sends: a workflow hung off this cannot go off every
 * hour about the same neglected ticket, because the sweep will not offer it
 * again for a day. That bound is why this is dispatched from the command rather
 * than from FindStaleTickets, which is a query and answers the same question as
 * often as it is asked.
 *
 * The reason is here because the two cases are different problems and want
 * different messages. "Over de afgesproken datum" is a promise that was broken;
 * "nog nooit beantwoord" is one nobody made, and it is the one a customer
 * notices first.
 */
class TicketWentStale
{
    use Dispatchable;

    /** @param string $reason 'overdue' when a due date passed, 'unanswered' when nobody replied at all. */
    public function __construct(
        public readonly int $ticketId,
        public readonly string $reason,
    ) {}
}
