<?php

namespace App\Events;

use App\Models\Document;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A document in this channel was started, saved, renamed or thrown away.
 *
 * Thin, like TicketUpdated and for a related reason — but the reason matters
 * more here. A document payload is the whole document, and broadcasting that on
 * every autosave would put a person's half-finished sentence on the wire every
 * few seconds, to everybody in the channel, whether or not any of them has the
 * document open. So this says only that something moved, and whoever cares
 * asks.
 *
 * Note that it must be broadcast with toOthers(). Autosave fires while somebody
 * types; a writer who received their own saves back would reload themselves in
 * a loop for as long as they kept writing.
 */
class DocumentUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The write runs in a transaction, so hold the broadcast until it commits —
     * otherwise a fast subscriber asks for the document and gets the old one.
     */
    public bool $afterCommit = true;

    public function __construct(public Document $document) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('chat.channel.'.$this->document->channel_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'documents.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->document->channel_id,
            /*
             * Which document, so a reader with one open can tell whether this
             * concerns the thing under their cursor or some other document in the
             * same channel — two situations that deserve very different
             * treatment. See use-document-activity.
             */
            'number' => $this->document->number,
        ];
    }
}
