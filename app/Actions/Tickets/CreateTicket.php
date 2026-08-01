<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Events\TicketUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateTicket
{
    public function __construct(
        private readonly RecordTicketEvent $recordTicketEvent,
        private readonly AnnounceTicket $announceTicket,
    ) {}

    /**
     * Open a ticket in a channel.
     *
     * The number is claimed inside the same transaction that writes the ticket:
     * a ticket that never gets stored must not take a number with it, or the
     * board grows gaps that look like deleted work.
     *
     * @param  Message|null  $source  The message this was promoted out of. Its
     *                                text is not copied — the ticket gets a
     *                                description of its own, so editing the
     *                                message later cannot quietly rewrite the
     *                                ticket. This is provenance, not content.
     */
    public function handle(
        Channel $channel,
        User $opener,
        string $title,
        string $body,
        TicketPriority $priority = TicketPriority::Normal,
        ?Message $source = null,
    ): Ticket {
        return DB::transaction(function () use ($channel, $opener, $title, $body, $priority, $source) {
            $ticket = Ticket::create([
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'number' => $channel->workspace->claimTicketNumber(),
                'title' => $title,
                'body' => $body,
                'priority' => $priority,
                'opened_by' => $opener->id,
                'source_message_id' => $source?->id,
            ]);

            $this->recordTicketEvent->handle($ticket, TicketEventType::Created, $opener);

            $this->announceTicket->opened($ticket);

            TicketUpdated::dispatch($ticket);

            return $ticket;
        });
    }
}
