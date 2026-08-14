<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Notifications\TestPush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

/**
 * The browsers a member has agreed to be interrupted on.
 *
 * Spoken to by the service worker registration in the browser rather than by a
 * form, so the answers here are bare status codes: there is nothing for a page
 * to render, and the caller is a fetch() that only wants to know whether it
 * worked. The body it sends is the shape PushSubscription.toJSON() hands out,
 * nested `keys` and all, so the browser can pass its own subscription straight
 * through without unpacking it first.
 */
class PushSubscriptionController extends Controller
{
    /**
     * Remember a browser, or remember it again.
     *
     * A browser re-offers the same subscription on every page load, so this has
     * to be idempotent — hence updateOrCreate on the endpoint rather than an
     * insert that would trip over the unique index the second time.
     */
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string', 'max:50'],
        ]);

        $user = $request->user();

        /*
         * A shared computer hands the same endpoint to whoever is logged in:
         * the browser keeps one subscription per installation, not per account.
         * Leaving the previous owner's row in place would quietly send this
         * member's messages to the person who used the machine before them, so
         * the old row goes before the new one is written.
         */
        PushSubscription::query()
            ->where('endpoint', $validated['endpoint'])
            ->whereNot('user_id', $user->id)
            ->delete();

        $user->pushSubscriptions()->updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['content_encoding'] ?? 'aes128gcm',
                // Kept so the settings screen can say "Chrome op deze Mac"
                // rather than offering a row of identical endpoints to pick
                // from when somebody wants to switch one browser off.
                'user_agent' => $request->userAgent(),
            ],
        );

        return response()->noContent();
    }

    /**
     * Provoke a bubble on purpose.
     *
     * Everything about web push fails quietly — a revoked permission, a stale
     * service worker, VAPID keys that do not match the ones the browser
     * subscribed with — and none of it is visible from here. So the answer is
     * not "we sent it": the channel stamps last_used_at on every subscription a
     * push service actually accepted, which makes counting the rows it touched
     * a real delivery count rather than a hopeful one.
     *
     * Sent to the subscriptions rather than to the member, so this works before
     * the preference above it has been ticked and saved. Somebody testing wants
     * to know whether the browser can be reached at all; whether they then want
     * to be reached is the checkbox's business, not this button's.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscriptions = $user->pushSubscriptions()->get();

        if ($subscriptions->isEmpty()) {
            return response()->json(['sent' => 0, 'delivered' => 0]);
        }

        $sentAt = now();

        Notification::route('webPush', $subscriptions)->notify(new TestPush);

        return response()->json([
            'sent' => $subscriptions->count(),
            'delivered' => $user->pushSubscriptions()
                ->where('last_used_at', '>=', $sentAt)
                ->count(),
        ]);
    }

    /**
     * Forget a browser.
     *
     * Scoped through the member's own subscriptions, so an endpoint belonging
     * to somebody else is simply not found rather than deleted. Silent when
     * there is nothing to delete: unsubscribing twice is not an error, and the
     * browser has already thrown its half away by the time it asks.
     */
    public function destroy(Request $request): Response
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $request->user()->pushSubscriptions()
            ->where('endpoint', $validated['endpoint'])
            ->delete();

        return response()->noContent();
    }
}
