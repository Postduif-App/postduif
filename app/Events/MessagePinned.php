<?php

namespace App\Events;

use App\Actions\Chat\PresentMessage;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A message was pinned to a channel, or unpinned from it.
 *
 * One event for both directions rather than two: what travels is the channel's
 * whole pin list, and "it changed, here is the new list" says the same thing
 * either way. Without this the bar would only appear after a reload — for
 * somebody who has to read the house rules that is exactly one reload too late.
 */
class MessagePinned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(public Message $message) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('chat.channel.'.$this->message->channel_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.pinned';
    }

    /**
     * The complete pin list, not the one message that changed.
     *
     * An absolute set for the same reason ReactionToggled sends one: a browser
     * may apply it twice, or next to a freshly rendered page, and still land on
     * the same bar. The message itself travels alongside so the marker on the
     * row in the conversation can follow without hunting through the list.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $presenter = app(PresentMessage::class);

        $pins = Message::query()
            ->where('channel_id', $this->message->channel_id)
            ->pinned()
            ->with(['author', 'pinner'])
            ->get();

        return [
            'channelId' => $this->message->channel_id,
            'messageId' => $this->message->id,
            'pinnedAt' => $this->message->pinned_at?->toIso8601String(),
            'pinnedBy' => $this->message->pinner?->name,
            'pins' => $presenter->pins($pins),
        ];
    }
}
