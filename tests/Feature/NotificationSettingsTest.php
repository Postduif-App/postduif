<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows a member their own notification settings', function () {
    $user = User::factory()->create([
        'notify_after_minutes' => 120,
        'notify_via_mail' => true,
        'notify_via_pushover' => true,
        'pushover_user_key' => 'u-sleutel-van-het-toestel',
    ]);

    actingAs($user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('preferences.notifyAfterMinutes', 120)
            ->where('preferences.viaMail', true)
            ->where('preferences.hasPushoverKey', true)
            // The key is a credential; the form only ever learns that one is
            // set, never what it is.
            ->missing('preferences.pushoverUserKey'));
});

it('saves a threshold and the delivery methods', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('notifications.update'), [
            'notify_after_minutes' => 240,
            'via_mail' => true,
            'via_pushover' => false,
        ])
        ->assertRedirect(route('notifications.edit'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->notify_after_minutes)->toBe(240)
        ->and($user->notify_via_mail)->toBeTrue()
        ->and($user->notify_via_pushover)->toBeFalse();
});

it('switches notifications off when no threshold is sent', function () {
    $user = User::factory()->create(['notify_after_minutes' => 120]);

    actingAs($user)
        ->patch(route('notifications.update'), ['via_mail' => true])
        ->assertRedirect();

    expect($user->refresh()->notify_after_minutes)->toBeNull()
        ->and($user->wantsAbsenceNotifications())->toBeFalse();
});

it('refuses a threshold that is not on offer', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->patch(route('notifications.update'), ['notify_after_minutes' => 7])
        ->assertSessionHasErrors('notify_after_minutes');

    expect($user->refresh()->notify_after_minutes)->toBeNull();
});

/**
 * The form cannot show the key, so it cannot send it back — and treating an
 * absent field as "cleared" would wipe it every time somebody changed the
 * threshold.
 */
it('leaves the pushover key alone when the field is not sent', function () {
    $user = User::factory()->create(['pushover_user_key' => 'u-sleutel-van-het-toestel']);

    actingAs($user)
        ->patch(route('notifications.update'), [
            'notify_after_minutes' => 60,
            'via_mail' => true,
        ])
        ->assertRedirect();

    expect($user->refresh()->pushover_user_key)->toBe('u-sleutel-van-het-toestel');
});

it('clears the pushover key when the field is sent empty', function () {
    $user = User::factory()->create(['pushover_user_key' => 'u-sleutel-van-het-toestel']);

    actingAs($user)
        ->patch(route('notifications.update'), [
            'notify_after_minutes' => 60,
            'via_mail' => true,
            'pushover_user_key' => '',
        ])
        ->assertRedirect();

    expect($user->refresh()->pushover_user_key)->toBeNull();
});

it('says pushover is unavailable when the install has no token', function () {
    config()->set('services.pushover.token', null);

    actingAs(User::factory()->create())
        ->get(route('notifications.edit'))
        ->assertInertia(fn ($page) => $page->where('pushoverAvailable', false));
});

it('keeps the settings screen away from a visitor', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});
