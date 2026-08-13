<?php

namespace App\Actions\Huddles;

use App\Events\HuddleUpdated;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Take out the people a huddle has stopped hearing from, and close what is left
 * empty.
 *
 * The half that leaving cannot cover. A browser that says goodbye is the easy
 * case; a browser that crashed, a laptop that shut, a train that went into a
 * tunnel — none of those send anything, ever. Without this the row keeps its
 * empty left_at, the huddle never runs out of people, and since a channel holds
 * one live huddle at a time, that channel is left with a conversation nobody
 * is in and no way to start another.
 */
class SweepStaleHuddles
{
    /**
     * How long somebody may go quiet before they are counted as gone.
     *
     * Three missed heartbeats — see HuddleHeartbeat::SECONDS. Long enough that
     * a laptop waking up or a tab throttled in the background is not thrown
     * out of a conversation it is still in, short enough that a channel is not
     * blocked for minutes by somebody who left.
     */
    public const AFTER_SECONDS = 90;

    /** @return int How many huddles were closed. */
    public function handle(): int
    {
        $closed = 0;

        DB::transaction(function () use (&$closed): void {
            HuddleParticipant::query()
                ->whereNull('left_at')
                ->where('last_seen_at', '<', now()->subSeconds(self::AFTER_SECONDS))
                ->update(['left_at' => now()]);

            /*
             * A recording whose recorder has gone quiet is over, whatever the
             * huddle does next. The browser holding the microphone is the only
             * one that could have stopped it politely, and by definition it did
             * not — so the indicator would otherwise stay lit for a
             * conversation that is no longer being recorded at all.
             */
            $abandoned = Huddle::query()
                ->live()
                ->whereNotNull('recording_by')
                // Only the ones still going: a huddle nobody is left in is
                // closed a few lines down, and that broadcast says everything
                // this one would.
                ->whereHas('present')
                ->whereDoesntHave('present', fn (Builder $query) => $query
                    ->whereColumn('huddle_participants.user_id', 'huddles.recording_by'))
                ->get();

            foreach ($abandoned as $huddle) {
                $huddle->stopRecording();
                HuddleUpdated::dispatch($huddle);
            }

            /*
             * Then the huddles nobody is left in. Asked as its own query rather
             * than derived from the rows above, because a huddle can also be
             * left empty by the ordinary way out — somebody pressing Weggaan
             * while the last other person had already gone quiet.
             */
            $empty = Huddle::query()
                ->live()
                ->whereDoesntHave('present')
                ->get();

            foreach ($empty as $huddle) {
                $huddle->forceFill(['ended_at' => now()])->save();
                HuddleUpdated::dispatch($huddle);
                $closed++;
            }
        });

        return $closed;
    }
}
