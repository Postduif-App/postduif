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

class ReactionToggled implements ShouldBroadcast
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
        return 'reaction.toggled';
    }

    /**
     * The complete reaction set for the message, not the one emoji that changed.
     *
     * An absolute set is idempotent: a browser can apply it twice, or apply it
     * next to a freshly rendered page, and still land on the same row of pills.
     * A "+1 on 👍" delta could not make that promise.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->message->channel_id,
            'messageId' => $this->message->id,
            'reactions' => app(PresentMessage::class)->reactions($this->message),
        ];
    }
}
