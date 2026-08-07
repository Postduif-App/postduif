<?php

namespace App\Actions\Timeclock;

use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Writing down a stretch the clock never saw.
 *
 * The other half of AdjustShift. Correcting covers the morning somebody clocked
 * in late; this covers the day they never clocked in at all — worked from a
 * customer's office, spent the afternoon on the road, or simply forgot until
 * the following week. Without it the only way to record such a day is to clock
 * in and immediately correct the times, which is asking somebody to record a
 * thing that is not true in order to then say what is.
 *
 * Always a finished stretch: both ends are required. A shift that is running is
 * something the clock says, not something you type — and the database allows a
 * member only one open shift per workspace, so an open one typed in here would
 * come back as a constraint violation rather than a sentence.
 *
 * Stamped as corrected on the way in, for the same reason AdjustShift stamps
 * one: hours a clock recorded and hours somebody typed are two different
 * claims, and the week should be able to tell them apart.
 */
class RecordShift
{
    public function __construct(private readonly GuardShift $guardShift) {}

    public function handle(User $member, Workspace $workspace, Carbon $startedAt, Carbon $endedAt): TimeEntry
    {
        /*
         * Made before it is guarded, and not saved by making it: GuardShift
         * needs to know whose week it is looking in, and an unsaved model is
         * the plainest way to hand it that without a second signature that
         * takes a member and a workspace loose.
         */
        $entry = $member->timeEntries()->make([
            'workspace_id' => $workspace->id,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
        ]);

        $this->guardShift->handle($entry, $startedAt, $endedAt);

        $entry->forceFill(['corrected_at' => Carbon::now()])->save();

        /*
         * No ClockPunched, deliberately. That event is what the workflow
         * triggers listen to — "X is ingeklokt" in a channel — and adding
         * yesterday's forgotten afternoon is not a punch happening now. For the
         * same reason the member's status is left alone: they are not at work
         * because they typed in that they were, last Tuesday.
         */

        return $entry;
    }
}
