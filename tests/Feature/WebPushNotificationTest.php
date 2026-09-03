<?php

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\SendsWebPush;
use App\Notifications\Messages\WebPushMessage;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\WebPush;

/**
 * A real VAPID pair, generated once for the suite. The library validates and
 * signs with these, so a made-up string will not do.
 */
beforeEach(function () {
    config()->set('services.webpush.subject', 'mailto:beheer@postduif.test');
    config()->set('services.webpush.public_key', 'BBJX-DYChFVjGep4looIdtZZI7PHlcRfaDgRU6la_0BwO-PwmkauVyRs4Ktq7IL43_ivJpvnzLystxsdG_6VMoc');
    config()->set('services.webpush.private_key', 'tI8f7HRgGZv9ZoQXMa6uy6EO_TRgE2DHOUTeQMHpPxI');
});

/**
 * Real browser keys, in pairs, because the library encrypts against them for
 * real — the factory's random strings are the right shape but not a point on the
 * P-256 curve, and openssl says so.
 *
 * @var array<int, array{public_key: string, auth_token: string}>
 */
const BROWSER_KEYS = [
    [
        'public_key' => 'BHCG1_ZTYqQLxLfO25dEI7v93bug5eviwgP-17NzZqCr97xoSp_AjksH80I-RUgS4SrAvth8fpjcXIkIrJtNaG8',
        'auth_token' => '5iewlht8ig_pacG5qQJFPg',
    ],
    [
        'public_key' => 'BAHvljEcEI-LDF3MK2f8X4SOGeIzVLqfAUDuWCK7KWmQ4nbZ097EJNx58RRWN6YHQ7l-wGco0PCjNfiDLpOYrU0',
        'auth_token' => 'ICDTTwOJhezSoVQgTZ78jQ',
    ],
];

/**
 * A member with one browser subscribed, and something to tell them.
 *
 * @return array{0: User, 1: StubWebPushNotification}
 */
function webPushFixture(int $browsers = 1): array
{
    $user = User::factory()->create([
        'notify_via_mail' => false,
        'notify_via_push' => true,
    ]);

    foreach (range(0, $browsers - 1) as $index) {
        PushSubscription::factory()->create([
            'user_id' => $user->id,
            ...BROWSER_KEYS[$index],
        ]);
    }

    return [$user->fresh(), new StubWebPushNotification];
}

class StubWebPushNotification extends Notification implements SendsWebPush
{
    public function toWebPush(User $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Studio',
            body: '3 ongelezen berichten',
            url: '/w/studio',
            tag: 'channel-activity-1',
        );
    }
}

class StubBareNotification extends Notification {}

it('sends an encrypted push to every browser the member has', function () {
    fakePushService([new Response(201), new Response(201)]);

    [$user, $notification] = webPushFixture(browsers: 2);

    app(WebPushChannel::class)->send($user, $notification);

    expect(PushSubscription::count())->toBe(2)
        ->and(PushSubscription::whereNull('last_used_at')->count())->toBe(0);
});

/**
 * The whole reason this channel reads its own responses. A browser that has been
 * wiped or has had its permission revoked never tells us — the 410 on the next
 * send is the only moment we find out, and not acting on it leaves a dead
 * endpoint that every later push pays for.
 */
it('deletes a subscription the push service has given up on', function () {
    fakePushService([new Response(410)]);

    [$user, $notification] = webPushFixture();

    app(WebPushChannel::class)->send($user, $notification);

    expect(PushSubscription::count())->toBe(0);
});

it('keeps a subscription that failed for a passing reason', function () {
    Log::spy();
    fakePushService([new Response(429)]);

    [$user, $notification] = webPushFixture();

    app(WebPushChannel::class)->send($user, $notification);

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::sole()->last_used_at)->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('sends nothing when the install has no vapid keys', function () {
    config()->set('services.webpush.private_key', null);

    app()->bind(WebPush::class, fn () => throw new RuntimeException('should not be built'));

    [$user, $notification] = webPushFixture();

    app(WebPushChannel::class)->send($user, $notification);

    expect(PushSubscription::sole()->last_used_at)->toBeNull();
});

it('sends nothing when the member has no browser subscribed', function () {
    app()->bind(WebPush::class, fn () => throw new RuntimeException('should not be built'));

    $user = User::factory()->create(['notify_via_push' => true]);

    app(WebPushChannel::class)->send($user, new StubWebPushNotification);
})->throwsNoExceptions();

it('sends nothing when the member has turned pushes off', function () {
    app()->bind(WebPush::class, fn () => throw new RuntimeException('should not be built'));

    [$user, $notification] = webPushFixture();
    $user->update(['notify_via_push' => false]);

    app(WebPushChannel::class)->send($user->fresh(), $notification);

    expect(PushSubscription::sole()->last_used_at)->toBeNull();
});

/**
 * Routed here without the contract is a mistake in via(). Named in the log
 * rather than fatal, because a queue worker is a bad place to find out.
 */
it('logs a notification that cannot become a web push', function () {
    Log::spy();
    app()->bind(WebPush::class, fn () => throw new RuntimeException('should not be built'));

    [$user] = webPushFixture();

    app(WebPushChannel::class)->send($user, new StubBareNotification);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'zonder toWebPush()'))
        ->once();
});

it('logs an unreachable push service instead of throwing', function () {
    Log::spy();
    app()->bind(WebPush::class, fn () => throw new RuntimeException('geen verbinding'));

    [$user, $notification] = webPushFixture();

    app(WebPushChannel::class)->send($user, $notification);

    Log::shouldHaveReceived('warning')->once();
    expect(PushSubscription::count())->toBe(1);
});

/**
 * A push service decrypts and stores whatever we hand it, and most of them are
 * not in the EU. The payload is therefore a summons, not a copy: no null keys
 * padding it out, and comfortably inside the ~4 KB a push may be after
 * encryption.
 */
it('stays inside the size a push may be after encryption', function () {
    $handler = new MockHandler([new Response(201)]);

    app()->bind(WebPush::class, fn ($app, array $parameters): WebPush => new WebPush(
        $parameters['auth'] ?? [],
        [],
        30,
        ['handler' => HandlerStack::create($handler)],
    ));

    [$user, $notification] = webPushFixture();

    app(WebPushChannel::class)->send($user, $notification);

    expect(strlen((string) $handler->getLastRequest()->getBody()))->toBeLessThanOrEqual(4096);
});
