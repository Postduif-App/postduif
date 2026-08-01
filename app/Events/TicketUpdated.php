<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Something about a ticket changed: it was opened, moved, answered or closed.
 *
 * Deliberately thin. Unlike a message, a ticket cannot be patched into place
 * from a payload — whether it belongs on the board depends on the filter the
 * reader has chosen and on counts taken over every ticket in the channel, none
 * of which the browser holds. So this only says "something moved here" and the
 * page asks the server for the props again.
 */
class TicketUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Every mutation runs in a transaction, so hold the broadcast until it
     * commits — otherwise a fast subscriber reloads and finds the old state.
     */
    public bool $afterCommit = true;

    public function __construct(public Ticket $ticket) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('chat.channel.'.$this->ticket->channel_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ticket.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->ticket->channel_id,
            'number' => $this->ticket->number,
        ];
    }
}
