<?php

namespace App\Notifications;

use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\SendsWebPush;
use App\Notifications\Messages\WebPushMessage;
use Illuminate\Notifications\Notification;

/**
 * "Did that work?" — the bubble somebody asks for from the settings screen.
 *
 * The only notification here that nobody schedules. Web push is the one delivery
 * method that cannot be proven from the server side: the permission, the service
 * worker and the tray all live in a browser we cannot see, and every step of it
 * fails silently by design. Without a button that provokes one on demand, the
 * first real proof arrives whenever the next absence digest happens to run.
 *
 * Deliberately not queued, and deliberately not gated on the member's
 * preference. Somebody who just pressed "test" is standing in front of the
 * screen waiting for the answer, so it goes out on the request rather than
 * whenever a worker gets to it — and it is sent to the browsers themselves
 * rather than to the member, so it can prove the plumbing before the preference
 * that uses it has been saved.
 */
class TestPush extends Notification implements SendsWebPush
{
    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * The parameter is widened from the contract's User on purpose: this one is
     * addressed to a bare set of subscriptions through Notification::route(),
     * so what arrives here is an AnonymousNotifiable rather than a member.
     */
    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: __('notifications.test_push.title'),
            body: __('notifications.test_push.body'),
            url: route('notifications.edit'),
            // Its own tag, so pressing the button twice replaces the first
            // bubble instead of stacking two identical ones — and renotify, so
            // that replacement still buzzes. Somebody testing wants to be
            // interrupted; that is the whole point of pressing it.
            tag: 'test-push',
            renotify: true,
        );
    }
}
