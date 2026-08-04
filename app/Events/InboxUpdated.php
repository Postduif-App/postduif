<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one member how much is waiting in their inbox, right now.
 *
 * Carries the count rather than a nudge to add one, which is the opposite of
 * what ChannelActivity does beside it — and deliberately. Inbox rows collapse:
 * the twentieth reply in a thread bumps a row that is already there, so a
 * client counting events upwards would climb away from the truth and only come
 * back on a page load. Sending the answer costs one indexed count and cannot
 * drift.
 *
 * Addressed per recipient for the same reason as ChannelActivity: what is
 * waiting for somebody is nobody else's business.
 */
class InboxUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $userId,
        public int $workspaceId,
        /** Unread rows in this workspace, of every kind. */
        public int $unread,
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
        return 'inbox.updated';
    }
}
