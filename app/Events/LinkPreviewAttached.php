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
 * A link somebody pasted turned out to be something, and the card can be drawn.
 *
 * The look-up happens on a queue, seconds after the message is already on
 * everybody's screen. Without this the card only appeared on the next reload —
 * which is how a working feature reads as a broken one: you paste a link, watch
 * nothing happen, and conclude the previews do not work.
 *
 * Its own event rather than a second MessageEdited. The payload is identical
 * and it would have worked, but "bewerkt" is a thing a person did to their own
 * words, and a listener that ever wants to act on that — a notification, a log
 * — must not be woken by our own bookkeeping.
 */
class LinkPreviewAttached implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
        return 'link-preview.attached';
    }

    /**
     * The whole message, exactly as MessageEdited sends it.
     *
     * The browser swaps the message out wholesale rather than patching one
     * field into it, which is what keeps a message that arrived over the socket
     * identical to the same message after a reload.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->message->channel_id,
            'message' => app(PresentMessage::class)->handle($this->message),
        ];
    }
}
