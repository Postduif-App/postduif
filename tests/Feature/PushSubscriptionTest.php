<?php

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * The body the browser sends: exactly what PushSubscription.toJSON() hands out,
 * nested `keys` and all.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function browserSubscription(array $overrides = []): array
{
    return array_merge([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(152),
        'keys' => [
            'p256dh' => Str::random(87),
            'auth' => Str::random(22),
        ],
    ], $overrides);
}

it('remembers a browser that agrees to be interrupted', function () {
    $user = User::factory()->create();
    $subscription = browserSubscription();

    actingAs($user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (X11; Linux x86_64; rv:129.0) Gecko/20100101 Firefox/129.0')
        ->postJson(route('push-subscriptions.store'), $subscription)
        ->assertNoContent();

    $stored = $user->pushSubscriptions()->sole();

    expect($stored->endpoint)->toBe($subscription['endpoint'])
        ->and($stored->public_key)->toBe($subscription['keys']['p256dh'])
        ->and($stored->auth_token)->toBe($subscription['keys']['auth'])
        ->and($stored->content_encoding)->toBe('aes128gcm')
        ->and($stored->user_agent)->toContain('Firefox/129.0');
});

it('takes the content encoding the browser asks for', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('push-subscriptions.store'), browserSubscription(['content_encoding' => 'aesgcm']))
        ->assertNoContent();

    expect($user->pushSubscriptions()->sole()->content_encoding)->toBe('aesgcm');
});

/**
 * A browser re-offers the same subscription on every page load, so this has to
 * be idempotent rather than an error the second time.
 */
it('does not duplicate a browser that offers itself again', function () {
    $user = User::factory()->create();
    $subscription = browserSubscription();

    actingAs($user)->postJson(route('push-subscriptions.store'), $subscription)->assertNoContent();

    $subscription['keys']['auth'] = 'een-nieuw-geheim';

    actingAs($user)->postJson(route('push-subscriptions.store'), $subscription)->assertNoContent();

    expect($user->pushSubscriptions()->count())->toBe(1)
        ->and($user->pushSubscriptions()->sole()->auth_token)->toBe('een-nieuw-geheim');
});

it('refuses a subscription without keys', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->postJson(route('push-subscriptions.store'), ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])
        ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

    expect($user->pushSubscriptions()->count())->toBe(0);
});

/**
 * A shared computer hands the same endpoint to whoever logs in next. If the
 * previous owner's row survives, they keep receiving the new member's messages
 * — a leak, not an edge case.
 */
it('moves an endpoint to the member who is logged in now', function () {
    $previous = User::factory()->create();
    $existing = PushSubscription::factory()->for($previous)->create();

    $next = User::factory()->create();

    actingAs($next)
        ->postJson(route('push-subscriptions.store'), browserSubscription([
            'endpoint' => $existing->endpoint,
            'keys' => ['p256dh' => Str::random(87), 'auth' => 'geheim-van-de-nieuwe'],
        ]))
        ->assertNoContent();

    expect($previous->pushSubscriptions()->count())->toBe(0)
        ->and(PushSubscription::query()->where('endpoint', $existing->endpoint)->count())->toBe(1)
        ->and($next->pushSubscriptions()->sole()->auth_token)->toBe('geheim-van-de-nieuwe');
});

it('forgets a browser that unsubscribes', function () {
    $user = User::factory()->create();
    $subscription = PushSubscription::factory()->for($user)->create();
    $other = PushSubscription::factory()->for($user)->firefox()->create();

    actingAs($user)
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $subscription->endpoint])
        ->assertNoContent();

    expect($user->pushSubscriptions()->pluck('id')->all())->toBe([$other->id]);
});

it('stays quiet when a browser unsubscribes twice', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->deleteJson(route('push-subscriptions.destroy'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/nooit-bestaan',
        ])
        ->assertNoContent();
});

it('never lets a member delete somebody else their browser', function () {
    $owner = User::factory()->create();
    $subscription = PushSubscription::factory()->for($owner)->create();

    actingAs(User::factory()->create())
        ->deleteJson(route('push-subscriptions.destroy'), ['endpoint' => $subscription->endpoint])
        ->assertNoContent();

    expect($owner->pushSubscriptions()->count())->toBe(1);
});

it('keeps a visitor from subscribing at all', function () {
    postJson(route('push-subscriptions.store'), browserSubscription())->assertUnauthorized();

    expect(PushSubscription::query()->count())->toBe(0);
});
