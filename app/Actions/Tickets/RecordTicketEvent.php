<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Events\TicketChanged;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;

/**
 * Writes down what happened to a ticket.
 *
 * Its own action rather than a line in each of the others, because everything
 * that changes a ticket has to leave a trace and a rule that has to be
 * remembered in five places is a rule that will be forgotten in one.
 *
 * Which is exactly why the event a workflow listens for is announced from here
 * too. Anything that writes a timeline row is a change worth acting on, and
 * putting the dispatch in each caller would be the five places again.
 */
class RecordTicketEvent
{
    /**
     * @param  User|null  $actor  Null when nothing with a face did this: a
     *                            webhook, or the scheduled reminder.
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        Ticket $ticket,
        TicketEventType $type,
        ?User $actor = null,
        array $payload = [],
    ): TicketEvent {
        $event = TicketEvent::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'payload' => $payload,
        ]);

        /*
         * After the commit — see the event. Every caller wraps this in a
         * transaction, and a workflow started before that commits would be
         * handed a ticket the queue cannot see yet.
         */
        TicketChanged::dispatch($ticket->id, $type, $actor?->id, $payload);

        return $event;
    }
}
