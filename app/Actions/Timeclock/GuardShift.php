<?php

namespace App\Actions\Timeclock;

use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What a stretch of time has to be true of before it may be stored.
 *
 * The same questions whether somebody is correcting what the clock recorded or
 * typing in an afternoon it never saw — see AdjustShift and RecordShift, which
 * both ask this and differ only in what they do afterwards. Kept in one place
 * because two copies of "does this overlap" is exactly the kind of pair that
 * drifts apart and then lets one route through what the other refuses.
 *
 * The messages come back on the field they belong to, so the form can put them
 * under the input somebody has to change.
 */
class GuardShift
{
    /**
     * @param  TimeEntry  $entry  May be unsaved — a stretch about to be added
     *                            only has its member and workspace filled in.
     * @param  Carbon|null  $endedAt  Null only ever leaves a running shift
     *                                running; it can neither reopen a finished one nor start one.
     *
     * @throws ValidationException
     */
    public function handle(TimeEntry $entry, Carbon $startedAt, ?Carbon $endedAt): void
    {
        $now = Carbon::now();

        if ($startedAt->greaterThan($now)) {
            throw ValidationException::withMessages([
                'startedAt' => __('timeclock.errors.in_the_future'),
            ]);
        }

        if ($endedAt === null) {
            /*
             * A finished shift cannot be reopened by clearing its end, and a
             * new one cannot be added open at all.
             *
             * Two open shifts is the one state the database refuses outright,
             * and it would refuse this one with a constraint violation rather
             * than a sentence anybody can read. Somebody who really did carry
             * on working starts a new stretch with the button, which is also
             * what happened.
             */
            if (! $entry->exists || ! $entry->isRunning()) {
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
        $others = TimeEntry::query()
            ->where('workspace_id', $entry->workspace_id)
            ->where('user_id', $entry->user_id)
            ->overlapping($startedAt, $until);

        /*
         * Only a stretch that is already stored can clash with itself. Asked of
         * `exists` rather than of the key, because whereKeyNot(null) reads as
         * `id != null` — never true, which would quietly wave every new stretch
         * through.
         */
        if ($entry->exists) {
            $others->whereKeyNot($entry->getKey());
        }

        if ($others->exists()) {
            throw ValidationException::withMessages([
                'startedAt' => __('timeclock.errors.overlaps'),
            ]);
        }
    }
}
