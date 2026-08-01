<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('starts everybody in the zone this is used in', function () {
    expect(User::factory()->create()->timezone)->toBe('Europe/Amsterdam');
});

it('lets somebody say where they are', function () {
    $user = User::factory()->create();

    actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'timezone' => 'Asia/Tokyo',
    ])->assertRedirect();

    expect($user->refresh()->timezone)->toBe('Asia/Tokyo');
});

/**
 * The dropdown is built from the same list the validator accepts, so this only
 * fires when something else made the request — but a zone the server cannot
 * read would make every repeating time in it meaningless.
 */
it('refuses a zone that is not a zone', function () {
    $user = User::factory()->create();

    actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'timezone' => 'Europe/Eerbeek',
    ])->assertSessionHasErrors('timezone');

    expect($user->refresh()->timezone)->toBe('Europe/Amsterdam');
});

it('offers the settings page every zone it will accept', function () {
    actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->has('timezones')
            ->where('timezones.0', 'Africa/Abidjan')
        );
});
