<?php

use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Psr7\Response;

/*
 * The button that proves web push works, which is the one part of the feature
 * that cannot be proven any other way from the server side.
 *
 * Uses the shared fakePushService() from tests/Pest.php, but carries its own
 * key pairs: the library encrypts against them for real, and the factory's random
 * strings are the right shape without being points on the P-256 curve, which
 * openssl rejects — and the channel swallows that, so the failure would show up
 * here as a quiet zero rather than as an error.
 *
 * @var array<int, array{public_key: string, auth_token: string}>
 */
const TEST_BUTTON_KEYS = [
    [
        'public_key' => 'BHCG1_ZTYqQLxLfO25dEI7v93bug5eviwgP-17NzZqCr97xoSp_AjksH80I-RUgS4SrAvth8fpjcXIkIrJtNaG8',
        'auth_token' => '5iewlht8ig_pacG5qQJFPg',
    ],
    [
        'public_key' => 'BAHvljEcEI-LDF3MK2f8X4SOGeIzVLqfAUDuWCK7KWmQ4nbZ097EJNx58RRWN6YHQ7l-wGco0PCjNfiDLpOYrU0',
        'auth_token' => 'ICDTTwOJhezSoVQgTZ78jQ',
    ],
];

/** A member with the given number of genuinely encryptable browsers. */
function memberWithBrowsers(int $browsers, bool $pushEnabled = true): User
{
    $user = User::factory()->create(['notify_via_push' => $pushEnabled]);

    foreach (range(0, $browsers - 1) as $index) {
        PushSubscription::factory()->create([
            'user_id' => $user->id,
            ...TEST_BUTTON_KEYS[$index],
        ]);
    }

    return $user->fresh();
}

beforeEach(function () {
    config()->set('services.webpush.subject', 'mailto:beheer@postduif.test');
    config()->set('services.webpush.public_key', 'BBJX-DYChFVjGep4looIdtZZI7PHlcRfaDgRU6la_0BwO-PwmkauVyRs4Ktq7IL43_ivJpvnzLystxsdG_6VMoc');
    config()->set('services.webpush.private_key', 'tI8f7HRgGZv9ZoQXMa6uy6EO_TRgE2DHOUTeQMHpPxI');
});

it('reports how many browsers the push services accepted', function () {
    $user = memberWithBrowsers(2);

    fakePushService([new Response(201), new Response(201)]);

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.test'))
        ->assertOk()
        ->assertJson(['sent' => 2, 'delivered' => 2]);
});

it('sends even when the member has not switched pushes on yet', function () {
    /*
     * The whole point of the button: somebody wants to know the browser can be
     * reached before committing to the preference that uses it. Routed at the
     * subscriptions rather than at the member, so the flag does not gate it.
     */
    $user = memberWithBrowsers(1, pushEnabled: false);

    fakePushService([new Response(201)]);

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.test'))
        ->assertOk()
        ->assertJson(['sent' => 1, 'delivered' => 1]);
});

it('reports nothing delivered when the push service refuses', function () {
    $user = memberWithBrowsers(1);

    fakePushService([new Response(410)]);

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.test'))
        ->assertOk()
        ->assertJson(['sent' => 1, 'delivered' => 0]);

    // A 410 is the push service saying the subscription is gone for good, so
    // the row goes with it rather than being retried forever.
    expect($user->pushSubscriptions()->count())->toBe(0);
});

it('says so when there is no browser to send to', function () {
    $user = User::factory()->create(['notify_via_push' => true]);

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.test'))
        ->assertOk()
        ->assertJson(['sent' => 0, 'delivered' => 0]);
});

it('never reaches somebody else browsers', function () {
    $user = User::factory()->create(['notify_via_push' => true]);
    memberWithBrowsers(1);

    $this->actingAs($user)
        ->postJson(route('push-subscriptions.test'))
        ->assertOk()
        ->assertJson(['sent' => 0, 'delivered' => 0]);
});

it('refuses a guest', function () {
    $this->postJson(route('push-subscriptions.test'))->assertUnauthorized();
});
