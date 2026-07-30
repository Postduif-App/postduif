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

class MessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The message is written inside a transaction, so hold the broadcast until
     * that transaction commits. Otherwise a fast subscriber can be told about a
     * message that a rolled-back transaction never actually stored.
     */
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
        return 'message.sent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->message->channel_id,
            'parentId' => $this->message->parent_id,
            'message' => app(PresentMessage::class)->handle($this->message),
        ];
    }
}
