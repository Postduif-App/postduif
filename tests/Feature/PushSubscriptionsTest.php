<?php

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('has web push switched off for a new account', function () {
    $user = User::factory()->create();

    expect($user->notify_via_push)->toBeFalse()
        ->and($user->pushSubscriptions)->toBeEmpty()
        ->and($user->wantsWebPush())->toBeFalse();
});

/**
 * The flag is the wish and a subscription is the only thing that can carry it
 * out, so neither half is the answer on its own.
 */
it('needs both the preference and a browser to send to', function () {
    $user = User::factory()->create(['notify_via_push' => true]);

    expect($user->wantsWebPush())->toBeFalse();

    PushSubscription::factory()->for($user)->create();

    expect($user->fresh()->wantsWebPush())->toBeTrue();
});

it('does not want web push while the preference is off', function () {
    $user = User::factory()->create(['notify_via_push' => false]);

    PushSubscription::factory()->for($user)->create();

    expect($user->wantsWebPush())->toBeFalse();
});

it('counts web push as a delivery method for absence notifications', function () {
    $user = User::factory()->create([
        'notify_after_minutes' => 120,
        'notify_via_mail' => false,
        'notify_via_pushover' => false,
        'notify_via_push' => true,
    ]);

    expect($user->wantsAbsenceNotifications())->toBeFalse();

    PushSubscription::factory()->for($user)->create();

    expect($user->fresh()->wantsAbsenceNotifications())->toBeTrue();
});

/**
 * A member reading Postduif on a phone and a laptop has two browsers, and
 * telling only one of them is telling half of them.
 */
it('routes a push to every browser the member allowed', function () {
    $user = User::factory()->create(['notify_via_push' => true]);

    $laptop = PushSubscription::factory()->for($user)->create();
    $phone = PushSubscription::factory()->for($user)->firefox()->create();

    PushSubscription::factory()->create();

    expect($user->routeNotificationForWebPush()->pluck('id')->all())
        ->toEqualCanonicalizing([$laptop->id, $phone->id]);
});

it('routes nowhere while the preference is off', function () {
    $user = User::factory()->create(['notify_via_push' => false]);

    PushSubscription::factory()->for($user)->create();

    expect($user->routeNotificationForWebPush())->toBeEmpty();
});

it('belongs to the member who allowed it', function () {
    $subscription = PushSubscription::factory()->create();

    expect($subscription->user)->toBeInstanceOf(User::class)
        ->and($subscription->user_agent)->not->toBeNull()
        ->and($subscription->content_encoding)->toBe('aes128gcm')
        ->and($subscription->last_used_at)->toBeNull();
});

/**
 * The endpoint is the identity of the subscription: a browser that re-subscribes
 * hands back the same one, and storing it twice would send everything twice.
 */
it('keeps one row per endpoint', function () {
    $subscription = PushSubscription::factory()->create();

    PushSubscription::factory()->create(['endpoint' => $subscription->endpoint]);
})->throws(QueryException::class);

it('lets go of the browsers when the account goes', function () {
    $user = User::factory()->create();

    PushSubscription::factory()->for($user)->count(2)->create();
    $other = PushSubscription::factory()->create();

    $user->delete();

    expect(DB::table('push_subscriptions')->count())->toBe(1)
        ->and(PushSubscription::first()->id)->toBe($other->id);
});

it('stamps a browser when something is sent to it', function () {
    $subscription = PushSubscription::factory()->create();

    $subscription->markUsed();

    expect($subscription->fresh()->last_used_at)->not->toBeNull();
});
