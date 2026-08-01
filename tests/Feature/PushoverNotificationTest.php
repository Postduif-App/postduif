<?php

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ChannelActivity;
use App\Notifications\Channels\PushoverChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('services.pushover.token', 'app-token');
});

/**
 * A member who wants pushes, and one channel's worth of missed activity.
 *
 * @return array{0: User, 1: ChannelActivity}
 */
function pushoverFixture(array $overrides = []): array
{
    $user = User::factory()->create([
        'notify_after_minutes' => 120,
        'notify_via_mail' => false,
        'notify_via_pushover' => true,
        'pushover_user_key' => 'u-sleutel-van-het-toestel',
        ...$overrides,
    ]);

    $notification = new ChannelActivity(
        Workspace::factory()->create(['name' => 'Studio']),
        new Collection([
            ['channelId' => 1, 'label' => '#klantproject', 'unread' => 3, 'mentions' => 1],
        ]),
    );

    return [$user, $notification];
}

it('posts a notification to pushover', function () {
    Http::fake(['api.pushover.net/*' => Http::response(['status' => 1])]);

    [$user, $notification] = pushoverFixture();

    app(PushoverChannel::class)->send($user, $notification);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.pushover.net/1/messages.json'
            && $request['token'] === 'app-token'
            && $request['user'] === 'u-sleutel-van-het-toestel'
            && str_contains($request['title'], 'Studio')
            && str_contains($request['message'], '#klantproject');
    });
});

it('sends nothing when the member has no key', function () {
    Http::fake();

    [$user, $notification] = pushoverFixture(['pushover_user_key' => null]);

    app(PushoverChannel::class)->send($user, $notification);

    Http::assertNothingSent();
});

it('sends nothing when the install has no application token', function () {
    Http::fake();
    config()->set('services.pushover.token', null);

    [$user, $notification] = pushoverFixture();

    app(PushoverChannel::class)->send($user, $notification);

    Http::assertNothingSent();
});

/**
 * A push usually goes out alongside a mail. An unreachable Pushover must not
 * take that mail down with it, or leave a failed job for something nobody can
 * act on — so it is logged and swallowed.
 */
it('logs a refusal instead of throwing', function () {
    Log::spy();
    Http::fake(['api.pushover.net/*' => Http::response(['errors' => ['user key is invalid']], 400)]);

    [$user, $notification] = pushoverFixture();

    app(PushoverChannel::class)->send($user, $notification);

    Log::shouldHaveReceived('warning')->once();
});

it('logs an unreachable pushover instead of throwing', function () {
    Log::spy();
    Http::fake(fn () => throw new RuntimeException('geen verbinding'));

    [$user, $notification] = pushoverFixture();

    app(PushoverChannel::class)->send($user, $notification);

    Log::shouldHaveReceived('warning')->once();
});

it('routes a notification to mail and pushover by preference', function () {
    Notification::fake();

    [$user] = pushoverFixture(['notify_via_mail' => true]);

    $notification = new ChannelActivity(
        Workspace::factory()->create(),
        new Collection([['channelId' => 1, 'label' => '#algemeen', 'unread' => 1, 'mentions' => 0]]),
    );

    expect($notification->via($user))->toBe(['mail', PushoverChannel::class]);

    $user->notify_via_pushover = false;

    expect($notification->via($user))->toBe(['mail']);
});
