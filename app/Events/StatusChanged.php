<?php

namespace App\Events;

use App\Enums\Availability;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Tells one member that somebody they share a channel with changed their
 * status.
 *
 * Addressed per recipient rather than broadcast once to the workspace, the same
 * choice ChannelActivity makes and for the same reason: a guest is in the
 * workspace only for the channels they were put in, and an event that reached
 * everybody would tell them who else exists. The set of recipients is worked
 * out once, in SetStatus.
 */
class StatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        /** Who is being told. */
        public int $recipientId,
        /** Whose status this is. */
        public int $userId,
        public ?string $emoji,
        public ?string $text,
        public Availability $availability,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->recipientId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.changed';
    }

    /**
     * The whole status, not the part that changed.
     *
     * Emoji, text and availability are read together everywhere they are drawn,
     * and a payload that carried only the difference would leave the browser
     * merging three fields it cannot tell apart from "unset".
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'userId' => $this->userId,
            'emoji' => $this->emoji,
            'text' => $this->text,
            'availability' => $this->availability->value,
        ];
    }
}
