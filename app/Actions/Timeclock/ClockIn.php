<?php

namespace App\Actions\Timeclock;

use App\Events\ClockPunched;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Starting a shift.
 *
 * Deliberately idempotent: somebody who is already clocked in and presses the
 * button again gets the shift they already had, not a second one and not an
 * error. Two open shifts is the one state this feature must never be in — the
 * totals stop meaning anything — and the button in the menu is exactly the kind
 * of thing that gets pressed twice.
 */
class ClockIn
{
    public function __construct(private readonly SyncClockStatus $syncClockStatus) {}

    public function handle(User $user, Workspace $workspace, ?Carbon $at = null): TimeEntry
    {
        $running = $user->openShiftIn($workspace);

        if ($running !== null) {
            return $running;
        }

        try {
            $entry = $user->timeEntries()->create([
                'workspace_id' => $workspace->id,
                'started_at' => $at ?? Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            /*
             * The other request got there first — a double click, or a second
             * tab. Both of them looked before writing and both saw nothing,
             * which is why the check above cannot be the thing that prevents
             * this; the partial unique index is. Whoever lost the race gets the
             * shift that did get written, which is the same answer they would
             * have got a moment earlier.
             */
            return $user->openShiftIn($workspace) ?? throw new \RuntimeException('Clocking in failed.');
        }

        $this->syncClockStatus->clockedIn($user);

        /*
         * After the status, and only for a shift that was actually opened: the
         * two early returns above hand back a shift that was already running,
         * and a workflow that fired on every stray double click would be a
         * message in a channel for something that did not happen.
         */
        ClockPunched::dispatch($workspace, $user, $entry, 'in');

        return $entry;
    }
}
