<?php

namespace App\Http\Controllers;

use App\Actions\Timeclock\AdjustShift;
use App\Actions\Timeclock\RecordShift;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Writing a stretch down by hand, putting one right, or taking one away.
 *
 * Only ever your own — TimeEntryPolicy says so, and it makes no exception for
 * whoever manages the workspace. A colleague with the right to read your hours
 * still cannot write them.
 *
 * The workspace is in the path because the whole group is — it guards the
 * feature — and an existing stretch is judged by whose it is rather than by
 * where it was requested from. Only store() reads the workspace, because a
 * stretch that does not exist yet has nobody to be asked about.
 *
 * The times arrive as wall clock readings in the member's own zone, because
 * that is what they typed: somebody correcting Tuesday means "half nine" on the
 * clock they were sitting in front of, and converting from the browser's zone
 * would quietly move it for anybody working away from home.
 */
class TimeEntryController extends Controller
{
    /**
     * Writing down a stretch by hand.
     *
     * Judged against the workspace rather than against a stretch, because there
     * is no stretch yet to ask about — WorkspacePolicy::clock, the same right
     * that lets somebody press the button, since typing in a day you worked is
     * not a bigger claim than clocking one.
     *
     * Both ends required, unlike a correction: see RecordShift for why an open
     * shift is the clock's to say and not the form's.
     */
    public function store(Request $request, Workspace $workspace, RecordShift $recordShift): RedirectResponse
    {
        $this->authorize('clock', $workspace);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'startedAt' => ['required', 'date_format:H:i'],
            'endedAt' => ['required', 'date_format:H:i'],
        ]);

        /** @var User $member */
        $member = $request->user();

        $startedAt = $this->moment($member, $validated['date'], $validated['startedAt']);
        $endedAt = $this->moment($member, $validated['date'], $validated['endedAt']);

        // Past midnight, exactly as a correction reads it — one date and two
        // times is the whole vocabulary the form has for a night shift.
        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            $endedAt->addDay();
        }

        $recordShift->handle($member, $workspace, $startedAt, $endedAt);

        return back()->with('status', __('flashes.timeclock.added'));
    }

    public function update(Request $request, Workspace $workspace, TimeEntry $timeEntry, AdjustShift $adjustShift): RedirectResponse
    {
        $this->authorize('update', $timeEntry);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'startedAt' => ['required', 'date_format:H:i'],
            /*
             * Absent for a shift that is still running, and only for that one:
             * AdjustShift refuses to reopen a finished stretch by clearing its
             * end, because the database would refuse it a moment later in a
             * language nobody can read.
             */
            'endedAt' => ['nullable', 'date_format:H:i'],
        ]);

        /** @var User $member */
        $member = $request->user();

        $startedAt = $this->moment($member, $validated['date'], $validated['startedAt']);

        $endedAt = $validated['endedAt'] === null
            ? null
            : $this->moment($member, $validated['date'], $validated['endedAt']);

        /*
         * An end before its start means the shift ran past midnight, which is
         * an evening's work and not a mistake — see TimeEntry::localDate, which
         * settles the same question for which day it counts towards. The form
         * asks for one date and two times precisely so this stays sayable.
         */
        if ($endedAt !== null && $endedAt->lessThanOrEqualTo($startedAt)) {
            $endedAt->addDay();
        }

        $adjustShift->handle($timeEntry, $startedAt, $endedAt);

        return back()->with('status', __('flashes.timeclock.adjusted'));
    }

    public function destroy(Request $request, Workspace $workspace, TimeEntry $timeEntry): RedirectResponse
    {
        $this->authorize('delete', $timeEntry);

        $timeEntry->delete();

        return back()->with('status', __('flashes.timeclock.removed'));
    }

    /**
     * A date and a time on the member's own clock, as the instant it was.
     */
    private function moment(User $member, string $date, string $time): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", $member->timezone)
            ->setTimezone(config('app.timezone'));
    }
}
