<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;

it('answers in the language the member chose', function () {
    $user = User::factory()->create(['locale' => 'en']);

    actingAs($user)->get(route('profile.edit'))->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('follows the browser when nobody has chosen', function () {
    $user = User::factory()->create(['locale' => null]);

    actingAs($user)
        ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get(route('profile.edit'))
        ->assertOk();

    /*
     * The state that matters most: somebody who never opened the setting still
     * gets a language they can read. Defaulting the column to Dutch would put
     * the fix behind a screen they cannot read either.
     */
    expect(app()->getLocale())->toBe('en');
});

it('lets the choice overrule the browser', function () {
    $user = User::factory()->create(['locale' => 'nl']);

    actingAs($user)
        ->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get(route('profile.edit'))
        ->assertOk();

    expect(app()->getLocale())->toBe('nl');
});

it('ignores a language it has no words for', function () {
    $user = User::factory()->create(['locale' => null]);

    actingAs($user)
        ->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')
        ->get(route('profile.edit'))
        ->assertOk();

    // Falls back to the first supported language rather than to French, which
    // would leave every string on the page untranslated.
    expect(app()->getLocale())->toBe('nl');
});

it('serves somebody without an account too', function () {
    // A download link, a request for a password, the public site: visited by
    // people who have nowhere to have set anything.
    $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->get(route('login'))
        ->assertOk();

    expect(app()->getLocale())->toBe('en');
});

it('stores the choice from the profile form', function () {
    $user = User::factory()->create(['locale' => null]);

    actingAs($user);

    patch(route('profile.update'), [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'timezone' => $user->timezone,
        'locale' => 'en',
    ])->assertRedirect();

    expect($user->fresh()->locale)->toBe('en');
});

it('reads "follow my browser" as no choice at all', function () {
    $user = User::factory()->create(['locale' => 'en']);

    actingAs($user);

    patch(route('profile.update'), [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'timezone' => $user->timezone,
        'locale' => 'auto',
    ])->assertRedirect();

    // Null rather than the string: a select cannot send nothing, so the value
    // it does send has to be turned back into the absence of one.
    expect($user->fresh()->locale)->toBeNull();
});

it('refuses a language that does not exist', function () {
    $user = User::factory()->create();

    actingAs($user);

    patch(route('profile.update'), [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'timezone' => $user->timezone,
        'locale' => 'kl',
    ])->assertSessionHasErrors('locale');
});
