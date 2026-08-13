<?php

namespace App\Events;

use App\Models\Huddle;
use App\Models\HuddleParticipant;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Somebody joined a huddle, left one, or started one.
 *
 * Carries who is in it rather than only saying that something moved, unlike
 * TicketUpdated: this is what the other browsers need to know whom to offer a
 * connection to, and asking the server again for a list of four names — every
 * time anybody's audio drops and comes back — is a round trip in the middle of
 * the one thing that has to feel immediate.
 */
class HuddleUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Every join runs in a transaction; hold the broadcast until it commits. */
    public bool $afterCommit = true;

    public function __construct(public Huddle $huddle) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        /*
         * The channel's own presence channel, which is the same set of people
         * the huddle is open to — ChannelPolicy::view guards both. No separate
         * huddle channel: a second thing to authorise is a second thing to get
         * wrong, and this one is already right.
         */
        return [new PresenceChannel('chat.channel.'.$this->huddle->channel_id)];
    }

    public function broadcastAs(): string
    {
        return 'huddle.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->huddle->id,
            'channelId' => $this->huddle->channel_id,
            'live' => $this->huddle->isLive(),
            /*
             * Who is recording, while somebody is. Carried here rather than
             * left to the message in the channel because the two answer
             * different questions: the message says it happened, this says it
             * is happening — and an indicator that only appeared after a page
             * reload would be a notice given too late to matter.
             */
            'recordingBy' => $this->huddle->isBeingRecorded()
                ? [
                    'id' => $this->huddle->recording_by,
                    'name' => $this->huddle->recorder?->name,
                ]
                : null,
            'participants' => $this->huddle
                ->present()
                ->with('user:id,name')
                ->get()
                ->map(fn (HuddleParticipant $participant): array => [
                    'id' => $participant->user_id,
                    'name' => $participant->user?->name,
                ])->all(),
        ];
    }
}
