<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;

/**
 * Writes down what happened to a ticket.
 *
 * Its own action rather than a line in each of the others, because everything
 * that changes a ticket has to leave a trace and a rule that has to be
 * remembered in five places is a rule that will be forgotten in one.
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
        return TicketEvent::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor?->id,
            'type' => $type,
            'payload' => $payload,
        ]);
    }
}
