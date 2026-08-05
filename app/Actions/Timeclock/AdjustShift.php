<?php

namespace App\Actions\Timeclock;

use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Putting a recorded stretch right.
 *
 * The reason this feature exists at all: people forget to clock in, forget to
 * clock out, and remember at four in the afternoon. Without a way to say what
 * actually happened, the honest thing to do with a wrong total is ignore it —
 * and a record everybody ignores is worse than none.
 *
 * Every change is stamped as a correction. Not to catch anybody out, but
 * because "8 uur" that a clock recorded and "8 uur" that somebody typed in
 * afterwards are two different claims, and the screen should be able to tell
 * them apart.
 */
class AdjustShift
{
    /**
     * @param  Carbon|null  $endedAt  Null leaves a running shift running. It
     *                                cannot end one that has already finished — see the guard below.
     */
    public function handle(TimeEntry $entry, Carbon $startedAt, ?Carbon $endedAt): TimeEntry
    {
        $this->guard($entry, $startedAt, $endedAt);

        $entry->forceFill([
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'corrected_at' => Carbon::now(),
        ])->save();

        return $entry;
    }

    /**
     * What a corrected stretch still has to be true of.
     *
     * @throws ValidationException
     */
    private function guard(TimeEntry $entry, Carbon $startedAt, ?Carbon $endedAt): void
    {
        $now = Carbon::now();

        if ($startedAt->greaterThan($now)) {
            throw ValidationException::withMessages([
                'startedAt' => __('timeclock.errors.in_the_future'),
            ]);
        }

        if ($endedAt === null) {
            /*
             * A finished shift cannot be reopened by clearing its end.
             *
             * Two open shifts is the one state the database refuses outright,
             * and it would refuse this one with a constraint violation rather
             * than a sentence anybody can read. Somebody who really did carry
             * on working starts a new stretch, which is also what happened.
             */
            if (! $entry->isRunning()) {
                throw ValidationException::withMessages([
                    'endedAt' => __('timeclock.errors.end_required'),
                ]);
            }

            $this->refuseOverlap($entry, $startedAt, $now);

            return;
        }

        if ($endedAt->greaterThan($now)) {
            throw ValidationException::withMessages([
                'endedAt' => __('timeclock.errors.in_the_future'),
            ]);
        }

        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            throw ValidationException::withMessages([
                'endedAt' => __('timeclock.errors.ends_before_it_starts'),
            ]);
        }

        $this->refuseOverlap($entry, $startedAt, $endedAt);
    }

    /**
     * That this stretch does not sit on top of another of the same member's.
     *
     * Overlapping stretches would be counted twice, and a week that reads fifty
     * hours because two rows describe the same afternoon is exactly the kind of
     * wrong nobody notices until it matters. Scoped to the workspace: two
     * employers' shifts running at the same time is somebody's business, but
     * not this table's problem to solve.
     *
     * @throws ValidationException
     */
    private function refuseOverlap(TimeEntry $entry, Carbon $startedAt, Carbon $until): void
    {
        $clash = TimeEntry::query()
            ->where('workspace_id', $entry->workspace_id)
            ->where('user_id', $entry->user_id)
            ->whereKeyNot($entry->getKey())
            ->overlapping($startedAt, $until)
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([
                'startedAt' => __('timeclock.errors.overlaps'),
            ]);
        }
    }
}
