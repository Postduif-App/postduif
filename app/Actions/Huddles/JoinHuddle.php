<?php

namespace App\Actions\Huddles;

use App\Events\HuddleUpdated;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Get somebody into the huddle in a channel, starting one if there is none.
 *
 * One action rather than a start and a join, because from where the button is
 * pressed they are the same gesture: you want to be in the conversation in this
 * channel. Which of the two it turns out to be is a fact about what the channel
 * was doing a moment ago, not about what was asked for.
 *
 * That also removes the race worth caring about. Two people pressing at the
 * same second used to be "who wins"; here the loser of the insert simply finds
 * the row the winner made and joins that — see the partial unique index the
 * migration adds.
 */
class JoinHuddle
{
    public function handle(Channel $channel, User $user): Huddle
    {
        return DB::transaction(function () use ($channel, $user): Huddle {
            $huddle = $this->live($channel, $user);

            HuddleParticipant::updateOrCreate(
                ['huddle_id' => $huddle->id, 'user_id' => $user->id],
                /*
                 * left_at back to null on the way in: somebody whose wifi
                 * dropped and who came back is here now, and a second row would
                 * turn "who is in this huddle" into a question about history.
                 */
                ['joined_at' => now(), 'last_seen_at' => now(), 'left_at' => null],
            );

            HuddleUpdated::dispatch($huddle);

            return $huddle;
        });
    }

    /**
     * The huddle going on in this channel, or a new one.
     *
     * The insert is tried rather than guarded by a preceding read: between a
     * read and a write another request fits, and the index is the only thing
     * that can say no at the moment it matters. A refused insert means somebody
     * else got there first, which is not a failure — it is the answer.
     */
    private function live(Channel $channel, User $user): Huddle
    {
        $existing = $channel->huddles()->live()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return $channel->huddles()->create(['started_by' => $user->id]);
        } catch (QueryException) {
            return $channel->huddles()->live()->sole();
        }
    }
}
