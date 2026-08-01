<?php

namespace App\Actions\Tickets;

use App\Actions\Chat\SendMessage;
use App\Enums\TicketStatus;
use App\Models\Ticket;

/**
 * Says in the conversation that a ticket was opened or closed.
 *
 * The two ends are announced wherever ticket_announcements is on. The moves in
 * between are a second, quieter setting that starts off: somebody reading along
 * has to learn that a ticket exists and that it is done, and a channel that
 * narrates every step by default is a channel people mute — after which the two
 * messages that mattered are gone too. A team that works out of the
 * conversation rather than out of the board can still ask for them.
 */
class AnnounceTicket
{
    /**
     * The name the announcement posts under. A constant rather than the
     * workspace's name: it is the same voice in every channel, and a reader
     * recognises it faster than a name that shifts.
     */
    private const BOT_NAME = 'Tickets';

    public function __construct(
        private readonly SendMessage $sendMessage,
    ) {}

    public function opened(Ticket $ticket): void
    {
        $this->announce($ticket, sprintf(
            'Nieuw ticket #%d — %s',
            $ticket->number,
            $ticket->title,
        ));
    }

    public function closed(Ticket $ticket): void
    {
        $this->announce($ticket, sprintf(
            'Ticket #%d gesloten — %s',
            $ticket->number,
            $ticket->title,
        ));
    }

    /**
     * A move that is neither opening nor closing: "in behandeling", "wacht op
     * klant".
     *
     * Its own setting, and its own guard: a channel that announces the two ends
     * has not thereby asked for the whole stream. Closing keeps going through
     * closed() instead, so switching this on cannot make one status change put
     * two bot messages in the channel.
     */
    public function statusChanged(Ticket $ticket, TicketStatus $from, TicketStatus $to): void
    {
        if (! $ticket->channel->ticket_status_announcements) {
            return;
        }

        $this->announce($ticket, sprintf(
            'Ticket #%d — %s: %s → %s',
            $ticket->number,
            $ticket->title,
            $from->label(),
            $to->label(),
        ));
    }

    /**
     * Nothing is said in a channel that switched announcements off, and nothing
     * in one that no longer keeps tickets at all — the second is what stops an
     * old ticket being closed from putting a bot message in a channel that has
     * long since moved on.
     */
    private function announce(Ticket $ticket, string $body): void
    {
        $channel = $ticket->channel;

        if (! $channel->hasTickets() || ! $channel->ticket_announcements) {
            return;
        }

        $this->sendMessage->fromSystem($channel, $body, self::BOT_NAME);
    }
}
