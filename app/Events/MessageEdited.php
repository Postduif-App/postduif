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

class MessageEdited implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** The edit runs in a transaction; see MessageSent for why this waits. */
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
        return 'message.edited';
    }

    /**
     * The whole message rather than just the new text.
     *
     * It goes through the same presenter as every other payload, so the
     * blocklist is applied to the new body as well and the browser can swap the
     * message out wholesale instead of patching fields it has to keep in step
     * with the server by hand.
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
