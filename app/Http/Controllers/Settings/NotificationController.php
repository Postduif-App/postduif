<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * When Postduif is allowed to reach somebody who is not looking at it.
 *
 * A member's own setting rather than a workspace one: how much interruption you
 * want is not something an administrator gets to decide for you.
 */
class NotificationController extends Controller
{
    /**
     * How long a member may be away from a channel before it is worth telling
     * them about it. Offered as a fixed list rather than a free number: the
     * difference between 95 and 100 minutes is not a decision anyone wants to
     * make, and it keeps the value inside what the schedule can honour.
     *
     * @var array<int, int>
     */
    private const THRESHOLDS = [30, 60, 120, 240, 480, 1440];

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'preferences' => [
                'notifyAfterMinutes' => $user->notify_after_minutes,
                'viaMail' => $user->notify_via_mail,
                'viaPushover' => $user->notify_via_pushover,
                // The key itself never travels back to the browser — it is a
                // credential. Whether one is set is all the form needs to know
                // to show "ingesteld" rather than an empty box.
                'hasPushoverKey' => filled($user->pushover_user_key),
            ],
            'thresholds' => array_map(
                fn (int $minutes): array => ['value' => $minutes, 'label' => $this->label($minutes)],
                self::THRESHOLDS,
            ),
            // Pushover needs an application token for the whole install. Without
            // one the option is offered as unavailable rather than as something
            // that would silently never arrive.
            'pushoverAvailable' => filled(config('services.pushover.token')),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notify_after_minutes' => ['nullable', Rule::in(self::THRESHOLDS)],
            'via_mail' => ['boolean'],
            'via_pushover' => ['boolean'],
            'pushover_user_key' => ['nullable', 'string', 'max:255'],
        ], [
            'notify_after_minutes.in' => __('requests.notifications.invalid_window'),
        ]);

        $user = $request->user();

        // Assigned rather than filled: User declares a deliberately narrow
        // Fillable, and mass assignment would drop these without a word.
        $user->notify_after_minutes = $validated['notify_after_minutes'] ?? null;
        $user->notify_via_mail = $validated['via_mail'] ?? false;
        $user->notify_via_pushover = $validated['via_pushover'] ?? false;

        // An absent field leaves the key alone: the form cannot show it, so it
        // cannot send it back, and treating "not sent" as "cleared" would wipe
        // the key every time somebody changed the threshold. An empty string is
        // the deliberate way to remove it.
        if ($request->has('pushover_user_key')) {
            $user->pushover_user_key = filled($validated['pushover_user_key'] ?? null)
                ? $validated['pushover_user_key']
                : null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.settings.notifications_saved')]);

        return to_route('notifications.edit');
    }

    private function label(int $minutes): string
    {
        /*
         * Four whole answers rather than a number with a word stuck to it. "60
         * minuten" is not what anybody says, and a language that inflects the
         * unit cannot be served by gluing one on at all.
         */
        return match (true) {
            $minutes < 60 => __('settings.notifications.duration_minutes', ['count' => $minutes]),
            $minutes === 60 => __('settings.notifications.duration_hour'),
            $minutes < 1440 => __('settings.notifications.duration_hours', ['count' => $minutes / 60]),
            default => __('settings.notifications.duration_day'),
        };
    }
}
