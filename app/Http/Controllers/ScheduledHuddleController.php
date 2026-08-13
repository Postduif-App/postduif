<?php

namespace App\Http\Controllers;

use App\Actions\Huddles\ScheduleHuddle;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\ScheduledHuddle;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Putting a huddle in the diary, and taking it back out.
 *
 * Guarded by the same policy as joining one: whoever may talk in this channel
 * may arrange to talk in it later. There is no separate right for it, and there
 * should not be — an appointment nobody may make is a channel where huddles are
 * off, which is a switch that already exists one level up.
 */
class ScheduledHuddleController extends Controller
{
    /** As far ahead as a huddle may be put. */
    private const MAX_DAYS_AHEAD = 90;

    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        ScheduleHuddle $schedule,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        $this->authorize('join', [Huddle::class, $channel]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            /*
             * A wall-clock moment from the browser, which sends it with its own
             * offset on it. Unlike a reminder — where the choices are words and
             * the server works out what they mean — this is a date somebody
             * typed into a picker, and the picker already knows which clock
             * they are looking at.
             */
            'starts_at' => ['required', 'date', 'after:now', 'before:'.now()->addDays(self::MAX_DAYS_AHEAD)->toDateString()],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'invitees' => ['array'],
            /*
             * Checked against the channel's own members rather than the users
             * table. Without the scope somebody could name anybody on the
             * installation, and the announcement writes their handle into the
             * channel — which would reach them through the mention machinery.
             */
            'invitees.*' => [
                'integer',
                Rule::exists('channel_user', 'user_id')->where('channel_id', $channel->id),
            ],
        ]);

        try {
            $scheduled = $schedule->handle(
                $channel,
                $request->user(),
                $validated['title'],
                Carbon::parse($validated['starts_at']),
                $validated['duration_minutes'],
                $validated['invitees'] ?? [],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['starts_at' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.huddle.scheduled', [
                'time' => $scheduled->starts_at
                    ->setTimezone($request->user()->timezone)
                    ->translatedFormat('D j M H:i'),
            ]),
        ]);

        return back();
    }

    /**
     * Calling it off.
     *
     * Whoever arranged it, or whoever runs the channel — the same pair that
     * answers for everything else about a channel. Deliberately not "anybody
     * invited": walking out of a meeting is declining it, and cancelling one
     * takes it away from the other four people as well.
     *
     * The row is kept and marked rather than deleted, so the dispatcher can
     * never announce it: a delete races a sweep that is already holding the
     * row, and a marked row cannot be claimed at all.
     */
    public function destroy(
        Request $request,
        Workspace $workspace,
        ScheduledHuddle $scheduled,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $scheduled->channel);

        abort_unless(
            $scheduled->created_by === $request->user()->id
                || $request->user()->can('manageSettings', $scheduled->channel),
            403,
        );

        $scheduled->forceFill(['cancelled_at' => now()])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.huddle.cancelled'),
        ]);

        return back();
    }
}
