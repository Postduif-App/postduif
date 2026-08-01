<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    /**
     * @param  bool  $tombstone  Whether the message stays on screen as a marker
     *                           because replies still hang off it.
     */
    public function __construct(
        public Message $message,
        public bool $tombstone,
    ) {}

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
        return 'message.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->message->channel_id,
            'messageId' => $this->message->id,
            'parentId' => $this->message->parent_id,
            // The parent's new total, absolute rather than a "-1" hint — the
            // same promise MessageSent makes, for the same reason.
            'parentReplyCount' => $this->message->parent_id === null
                ? null
                : Message::withTrashed()
                    ->whereKey($this->message->parent_id)
                    ->value('reply_count'),
            'tombstone' => $this->tombstone,
        ];
    }
}
