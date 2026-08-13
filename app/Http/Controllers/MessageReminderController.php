<?php

namespace App\Http\Controllers;

use App\Actions\Chat\ScheduleReminder;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

/**
 * Putting a message aside to be brought back later.
 *
 * No policy of its own beyond being able to read the channel, and deliberately
 * so: a reminder is a note to yourself about somebody else's sentence. It
 * changes nothing anybody else can see, tells the author nothing, and is not an
 * act the channel has any say over.
 *
 * The moment is worked out here rather than sent as a wall-clock time, because
 * the offsets on the menu — "over een uur", "morgenochtend" — mean something
 * different in every timezone, and the one authority on which timezone somebody
 * is in is their own profile.
 */
class MessageReminderController extends Controller
{
    /**
     * How long each choice on the menu is worth, in minutes.
     *
     * A fixed list rather than a free number: the endpoint is reachable by
     * anybody, and "in 400 years" is a row that sits in the table forever. The
     * two named ones are not offsets at all and are handled below.
     */
    private const OFFSETS = [
        '20m' => 20,
        '1h' => 60,
        '3h' => 180,
    ];

    public function store(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        ScheduleReminder $schedule,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);
        abort_unless($message->channel_id === $channel->id, 404);
        $this->authorize('view', $channel);

        $validated = $request->validate([
            'when' => ['required', 'string', 'in:'.implode(',', [...array_keys(self::OFFSETS), 'tomorrow', 'next_week'])],
            // Why you wanted reminding. Short on purpose: this is a label on a
            // row in a list, not a place to write the answer you owe somebody.
            'note' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $reminder = $schedule->handle(
                $request->user(),
                $message,
                $this->moment($request->user()->timezone, $validated['when']),
                $validated['note'] ?? null,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['when' => $exception->getMessage()]);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.reminder.set', [
                // In their own timezone, because it is the clock they will be
                // looking at when it goes off.
                'time' => $reminder->remind_at
                    ->setTimezone($request->user()->timezone)
                    ->translatedFormat('D j M H:i'),
            ]),
        ]);

        return back();
    }

    /**
     * Calling one off.
     *
     * Bound by id and then checked against the member, rather than scoped in
     * the route: a reminder belongs to a person and not to the channel it is
     * about, so there is nothing in the path to scope it by. Somebody else's id
     * is a 404 — whether a reminder exists is itself private.
     */
    public function destroy(Request $request, Workspace $workspace, Reminder $reminder): RedirectResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);

        $reminder->delete();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('flashes.reminder.cancelled'),
        ]);

        return back();
    }

    /**
     * When "over een uur" and "morgenochtend" actually fall.
     *
     * The two named choices are clock times rather than offsets, and that is
     * the point of having them: "morgenochtend" set at half past midnight means
     * the morning that is a few hours away, not one twenty-four hours later.
     */
    private function moment(string $timezone, string $when): CarbonInterface
    {
        $now = now()->setTimezone($timezone);

        return match ($when) {
            'tomorrow' => $now->copy()->addDay()->setTime(9, 0),
            /*
             * Monday morning, not "seven days from now". Somebody putting
             * something off to next week means the start of it, and a reminder
             * that arrived on Saturday at 16:12 because that is when they
             * clicked would be one they have to put off again.
             */
            'next_week' => $now->copy()->next(CarbonInterface::MONDAY)->setTime(9, 0),
            default => $now->copy()->addMinutes(self::OFFSETS[$when]),
        };
    }
}
