<?php

namespace App\Actions\Timeclock;

use App\Events\ClockPunched;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Ending the shift that was running.
 *
 * Null when there was nothing to end. Not an error: the request that arrives
 * twice, or the tab that was left open on yesterday's page, is asking for a
 * state the member is already in — and answering "you were not clocked in" with
 * a failure would make the button behave differently depending on how many tabs
 * somebody has.
 */
class ClockOut
{
    public function __construct(private readonly SyncClockStatus $syncClockStatus) {}

    public function handle(User $user, Workspace $workspace, ?Carbon $at = null): ?TimeEntry
    {
        $running = $user->openShiftIn($workspace);

        if ($running === null) {
            return null;
        }

        /*
         * The real moment, even when that makes for an implausibly long shift.
         *
         * Trimming it to the sixteen-hour ceiling here would be the application
         * inventing an end time nobody was present for. The ceiling belongs
         * where the hours are added up — TimeEntry::seconds() — and the screen
         * says out loud that a stretch went over it, so the member can correct
         * it to what actually happened.
         */
        $running->forceFill(['ended_at' => $at ?? Carbon::now()])->save();

        $this->syncClockStatus->clockedOut($user);

        // Only where a shift was really closed — the early return above covers
        // pressing the button twice, which is not an event that happened.
        ClockPunched::dispatch($workspace, $user, $running, 'out');

        return $running;
    }
}
