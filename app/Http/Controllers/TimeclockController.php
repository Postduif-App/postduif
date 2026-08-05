<?php

namespace App\Http\Controllers;

use App\Actions\Chat\BuildChatShell;
use App\Actions\Timeclock\ClockIn;
use App\Actions\Timeclock\ClockOut;
use App\Actions\Timeclock\SummariseHours;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The clock, and the week it recorded.
 *
 * Inside the chat shell rather than under settings, the same choice the ticket,
 * transfer, secret and form lists make. Settings is where a workspace is
 * configured once and left alone; clocking in is the first thing somebody does
 * in the morning, and "heb ik genoeg gedraaid" is a question you ask between
 * two conversations rather than one you navigate away for.
 *
 * Clocking in and out is not only on this screen: the same two endpoints are
 * what the user menu presses, from wherever somebody happens to be.
 *
 * The workspace is in the path, so the feature middleware guards the whole
 * group and a workspace with tijdregistratie switched off has no such address
 * at all. What is left here is the role — WorkspacePolicy::clock, which keeps
 * guests out of a clock that is not about them.
 */
class TimeclockController extends Controller
{
    public function __construct(private readonly BuildChatShell $buildChatShell) {}

    /**
     * How far back the screen will walk.
     *
     * Far enough to settle an argument about last month, short enough that the
     * arrows are not an invitation to browse somebody's year. Anything older is
     * a question about payroll rather than about this week.
     */
    private const MAX_WEEKS_BACK = 26;

    public function index(Request $request, Workspace $workspace, SummariseHours $summariseHours): Response
    {
        $this->authorize('clock', $workspace);

        /** @var User $member */
        $member = $request->user();

        $anchor = $this->anchor($request);

        $running = $member->openShiftIn($workspace);

        return Inertia::render('chat/timeclock', [
            ...$this->buildChatShell->handle($workspace, $member),

            /*
             * The running shift, separately from the week it falls in. The
             * button needs it whichever week is being read, and a member
             * looking back at March should still be able to clock out.
             */
            'running' => $running === null ? null : [
                'id' => $running->id,
                'startedAt' => $running->started_at->toIso8601String(),
                'seconds' => $running->seconds(),
            ],
            'week' => $summariseHours->forMember($member, $workspace, $anchor),
            /*
             * The half year above the week, and always the *last* half year
             * rather than the one around whichever week is being read: it is
             * the map you navigate with, and a map that moved with you would be
             * a second week view.
             */
            'calendar' => $summariseHours->calendar($member, $workspace, self::MAX_WEEKS_BACK + 1),
            'weeksBack' => $this->weeksBack($request),
            'maxWeeksBack' => self::MAX_WEEKS_BACK,
            'setsStatus' => $member->clock_sets_status,
            /*
             * The colleagues' column, or null when this member may not look.
             * Null rather than an empty list: "nobody worked" and "this is not
             * yours to read" are different things, and the screen says
             * different things about them.
             */
            'colleagues' => $member->can('seeHours', $workspace)
                ? $summariseHours->forWorkspace($workspace, $member, $anchor)
                : null,
        ]);
    }

    public function clockIn(Request $request, Workspace $workspace, ClockIn $clockIn): RedirectResponse
    {
        $this->authorize('clock', $workspace);

        $clockIn->handle($request->user(), $workspace);

        return back()->with('status', __('flashes.timeclock.clocked_in'));
    }

    public function clockOut(Request $request, Workspace $workspace, ClockOut $clockOut): RedirectResponse
    {
        $this->authorize('clock', $workspace);

        $entry = $clockOut->handle($request->user(), $workspace);

        /*
         * Nothing was running. Not an error — see ClockOut — so the member is
         * simply told where they stand rather than shown a failure for pressing
         * a button that was already done.
         */
        if ($entry === null) {
            return back()->with('status', __('flashes.timeclock.not_clocked_in'));
        }

        return back()->with('status', __('flashes.timeclock.clocked_out', [
            'duration' => $this->spoken($entry),
        ]));
    }

    /**
     * Whether the clock may move this member's status along with it.
     *
     * Stored on the member rather than on the membership, so somebody in two
     * workspaces answers it once — status is one thing across all of them, and
     * a per-workspace answer could contradict itself about the same status.
     */
    public function updatePreference(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('clock', $workspace);

        $validated = $request->validate([
            'setsStatus' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill([
            'clock_sets_status' => $validated['setsStatus'],
        ])->save();

        return back()->with('status', __('settings.actions.saved'));
    }

    /**
     * The moment the requested week is read around.
     *
     * Counted in whole weeks back from now rather than passed as a date, so
     * there is no way to ask for a week that does not line up with one — and no
     * date arriving from a browser that has to be believed.
     */
    private function anchor(Request $request): Carbon
    {
        return Carbon::now()->subWeeks($this->weeksBack($request));
    }

    private function weeksBack(Request $request): int
    {
        return min(max((int) $request->integer('weeks'), 0), self::MAX_WEEKS_BACK);
    }

    /** "7 uur en 45 minuten", for the sentence that confirms a shift is over. */
    private function spoken(TimeEntry $entry): string
    {
        $minutes = intdiv($entry->seconds(), 60);

        return __('timeclock.spoken_duration', [
            'hours' => intdiv($minutes, 60),
            'minutes' => $minutes % 60,
        ]);
    }
}
