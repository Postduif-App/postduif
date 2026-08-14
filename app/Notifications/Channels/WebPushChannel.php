<?php

namespace App\Notifications\Channels;

use App\Models\PushSubscription;
use App\Notifications\Contracts\SendsWebPush;
use App\Notifications\Messages\WebPushMessage;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Delivery over Web Push, which turns a notification into a bubble in a
 * browser's own notification tray — on a laptop that has the tab closed, or on a
 * phone with the site installed.
 *
 * Written as a channel rather than as a call inside a job so it sits where mail
 * does: one notification decides what it says, and via() decides where it goes.
 *
 * The encryption is left to minishlink/web-push. RFC 8291 is not something to
 * reimplement with Http and a handful of openssl calls, and the push services
 * will not accept anything less.
 */
class WebPushChannel
{
    /**
     * Send it to every browser the member has, and swallow anything that goes
     * wrong.
     *
     * Deliberately quiet, for the same reason PushoverChannel is: a notification
     * usually goes out by mail as well, and a push service that is down, rate
     * limiting us, or refusing our VAPID keys must not take that mail down with
     * it or leave a failed job in the queue for something nobody can act on. It
     * is logged, which is where you look when somebody says their browser stayed
     * silent.
     */
    public function send(mixed $notifiable, Notification $notification): void
    {
        $subscriptions = $notifiable->routeNotificationFor('webPush', $notification);

        if (! $subscriptions instanceof EloquentCollection || $subscriptions->isEmpty()) {
            return;
        }

        $auth = $this->vapid();

        if ($auth === null) {
            return;
        }

        /*
         * Anything routed here without the contract is a mistake in via(), not
         * something to guess at: returning quietly beats a fatal error in a
         * queue worker, and the log line names what to fix.
         */
        if (! $notification instanceof SendsWebPush) {
            Log::warning('Web push-notificatie zonder toWebPush()', [
                'notification' => $notification::class,
            ]);

            return;
        }

        $payload = $this->payload($notification->toWebPush($notifiable));

        try {
            $webPush = app(WebPush::class, ['auth' => ['VAPID' => $auth]]);

            /** @var PushSubscription $subscription */
            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding,
                    ]),
                    $payload,
                );
            }

            $this->handleReports($webPush->flush(), $subscriptions);
        } catch (Throwable $exception) {
            Log::warning('Could not reach the push services.', ['exception' => $exception->getMessage()]);
        }
    }

    /**
     * Act on what each push service said back.
     *
     * The reason this channel is more than a loop over Http. A browser can be
     * wiped, reinstalled or have its permission revoked without ever telling us,
     * and the row here looks exactly the same afterwards — the only moment we
     * ever learn is the 404 or 410 that comes back on the next send. Not acting
     * on it means the table fills up with dead endpoints and every later push
     * pays for them in requests. Anything else is a bad day at the push service
     * rather than a verdict on the subscription, so it is only logged.
     *
     * @param  iterable<int, MessageSentReport>  $reports
     * @param  EloquentCollection<int, PushSubscription>  $subscriptions
     */
    private function handleReports(iterable $reports, EloquentCollection $subscriptions): void
    {
        $byEndpoint = $subscriptions->keyBy('endpoint');

        foreach ($reports as $report) {
            /** @var PushSubscription|null $subscription */
            $subscription = $byEndpoint->get($report->getEndpoint());

            if ($report->isSuccess()) {
                $subscription?->markUsed();

                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $subscription?->delete();

                continue;
            }

            Log::warning('A push service refused a notification.', [
                'status' => $report->getResponse()?->getStatusCode(),
                // The endpoint is a capability to interrupt this member's
                // browser, so it is deliberately not logged; the reason the
                // service gave is what you can act on.
                'reason' => $report->getReason(),
            ]);
        }
    }

    /**
     * The VAPID identity we sign with, or null when this install has none.
     *
     * Half a pair is worse than none: the library would raise on a key it cannot
     * validate, which is an exception in a queue worker over a thing that is
     * simply not configured yet.
     *
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    private function vapid(): ?array
    {
        $subject = config('services.webpush.subject');
        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');

        if (blank($subject) || blank($publicKey) || blank($privateKey)) {
            return null;
        }

        return [
            'subject' => (string) $subject,
            'publicKey' => (string) $publicKey,
            'privateKey' => (string) $privateKey,
        ];
    }

    /**
     * The JSON the service worker will be handed.
     *
     * Kept small on purpose twice over. A push payload may not exceed about 4 KB
     * once encrypted and padded, and everything in it is decrypted by a push
     * service we do not run — so only the keys the browser actually renders are
     * sent, nulls are dropped, and nothing that reads as message content or as
     * somebody's name goes in beyond what the notification already chose to put
     * in its title and body.
     */
    private function payload(WebPushMessage $message): string
    {
        return (string) json_encode(array_filter([
            'title' => $message->title,
            'body' => $message->body,
            'icon' => $message->icon,
            'badge' => $message->badge,
            'url' => $message->url,
            'tag' => $message->tag,
            'renotify' => $message->renotify ?: null,
        ], fn (mixed $value): bool => $value !== null));
    }
}
