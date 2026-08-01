<?php

namespace App\Notifications\Channels;

use App\Notifications\Contracts\SendsPushover;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivery over Pushover, which turns a notification into a push on somebody's
 * own phone.
 *
 * Written as a channel rather than as a call inside a job so it sits where mail
 * does: one notification decides what it says, and via() decides where it goes.
 */
class PushoverChannel
{
    private const ENDPOINT = 'https://api.pushover.net/1/messages.json';

    /**
     * Send it, and swallow anything that goes wrong.
     *
     * Deliberately quiet. A notification usually goes out by mail as well, and
     * an unreachable Pushover — a wrong key, an outage, a rate limit — must not
     * take the mail down with it or leave a failed job in the queue for
     * something nobody can act on. It is logged, which is where you look when
     * somebody says their phone stayed silent.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $userKey = $notifiable->routeNotificationFor('pushover', $notification);
        $token = config('services.pushover.token');

        if (blank($userKey) || blank($token)) {
            return;
        }

        /*
         * Anything routed here without the contract is a mistake in via(), not
         * something to guess at: returning quietly beats a fatal error in a
         * queue worker, and the log line names what to fix.
         */
        if (! $notification instanceof SendsPushover) {
            Log::warning('Pushover-notificatie zonder toPushover()', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $message = $notification->toPushover($notifiable);

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post(self::ENDPOINT, [
                    'token' => $token,
                    'user' => $userKey,
                    'title' => $message->title,
                    'message' => $message->body,
                    ...array_filter(['url' => $message->url, 'url_title' => $message->urlTitle]),
                ]);

            if ($response->failed()) {
                Log::warning('Pushover refused a notification.', [
                    'status' => $response->status(),
                    // Pushover answers with the reason in "errors"; the user key
                    // is deliberately not logged.
                    'errors' => $response->json('errors'),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Could not reach Pushover.', ['exception' => $exception->getMessage()]);
        }
    }
}
