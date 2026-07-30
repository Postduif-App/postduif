<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one member that something happened in a channel they are not currently
 * looking at, so their sidebar badge can move without a page load.
 *
 * Deliberately addressed per recipient rather than broadcast once to the whole
 * workspace. A single workspace-wide event would be cheaper, but it would also
 * tell everyone that a private channel just received a message — the member
 * list is secret, so the fact that it is busy should be too.
 */
class ChannelActivity implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $userId,
        public int $channelId,
        /** Root messages move the channel badge; thread replies do not. */
        public bool $isReply,
        public bool $mentioned,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'channel.activity';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'channelId' => $this->channelId,
            'isReply' => $this->isReply,
            'mentioned' => $this->mentioned,
        ];
    }
}
