<?php

use App\Enums\SystemRole;
use App\Models\SecretValue;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows the requester which keys are in, without the values', function () {
    [$request, $password, , $guest, $requester] = fillableRequest();

    SecretValue::record($password, 'hunter2-geheim', $guest);

    $response = actingAs($requester)
        ->get(route('secrets.answers', $request))
        ->assertOk();

    // Not in the props, and not anywhere in the rendered page either — an
    // Inertia payload is embedded in the HTML and kept in history.
    expect($response->getContent())->not->toContain('hunter2-geheim');

    $response->assertInertia(fn ($page) => $page
        ->component('secrets/answers')
        ->has('request.keys', 2)
        ->where('request.keys.0.isAnswered', true)
        ->where('request.keys.0.filledBy', $guest->name)
        ->where('request.keys.1.isAnswered', false)
        ->missing('request.keys.0.value')
    );
});

it('hands over one value when it is asked for outright', function () {
    [$request, $password, , $guest, $requester] = fillableRequest();

    SecretValue::record($password, 'hunter2-geheim', $guest);

    $response = actingAs($requester)
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertOk()
        ->assertJson(['value' => 'hunter2-geheim', 'burned' => false]);

    /*
     * Without no-store a proxy or the browser is entitled to keep the body.
     * Asserted as a substring because Symfony reorders the directives and adds
     * "private" of its own accord — pinning the exact string would make this
     * test about the framework's formatting rather than about the rule.
     */
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('records that the value came back out', function () {
    [$request, $password, , $guest, $requester] = fillableRequest();

    $value = SecretValue::record($password, 'geheim', $guest);
    expect($value->revealed_at)->toBeNull();

    actingAs($requester)->post(route('secrets.reveal', [$request, $password]));

    expect($value->refresh()->revealed_at)->not->toBeNull();
});

/**
 * The property that makes this worth more than a chat message: the list of
 * people who can read it is one name long.
 */
it('does not let the person who filled it read it back', function () {
    [$request, $password, , $guest] = fillableRequest();

    SecretValue::record($password, 'geheim', $guest);

    actingAs($guest)->get(route('secrets.answers', $request))->assertForbidden();
    actingAs($guest)->post(route('secrets.reveal', [$request, $password]))->assertForbidden();
});

it('does not let a workspace admin read it', function () {
    [$request, $password, , $guest] = fillableRequest();

    SecretValue::record($password, 'geheim', $guest);

    $admin = User::factory()->create();
    $request->workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    actingAs($admin)->get(route('secrets.answers', $request))->assertForbidden();
    actingAs($admin)->post(route('secrets.reveal', [$request, $password]))->assertForbidden();
});

it('does not let an outsider near it', function () {
    [$request, $password, , $guest] = fillableRequest();
    SecretValue::record($password, 'geheim', $guest);

    actingAs(User::factory()->create())
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertForbidden();
});

/** A key from another request must not resolve through this one. */
it('refuses a key that belongs to a different request', function () {
    [$request, , , , $requester] = fillableRequest();
    [, $strangerKey, , $otherGuest] = fillableRequest();

    SecretValue::record($strangerKey, 'geheim', $otherGuest);

    actingAs($requester)
        ->post(route('secrets.reveal', [$request, $strangerKey]))
        ->assertNotFound();
});

it('has nothing to show for a key nobody answered', function () {
    [$request, $password, , , $requester] = fillableRequest();

    actingAs($requester)
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertNotFound();
});

/**
 * The sharpest setting: a password going straight into a server is safest when
 * it stops existing the moment it has been read.
 */
it('destroys the value on reading it when the request says so', function () {
    [$request, $password, , $guest, $requester] = fillableRequest([
        'burn_after_reading' => true,
    ]);

    SecretValue::record($password, 'eenmalig-geheim', $guest);

    actingAs($requester)
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertOk()
        ->assertJson(['value' => 'eenmalig-geheim', 'burned' => true]);

    expect(SecretValue::count())->toBe(0);

    // And a second look finds nothing at all, rather than an empty string.
    actingAs($requester)
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertNotFound();
});

it('keeps the value for a second look when the request does not burn', function () {
    [$request, $password, , $guest, $requester] = fillableRequest();

    SecretValue::record($password, 'geheim', $guest);

    actingAs($requester)->post(route('secrets.reveal', [$request, $password]))->assertOk();
    actingAs($requester)
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertOk()
        ->assertJson(['value' => 'geheim']);
});

/** Expiry stops answers coming in; it does not lock the requester out. */
it('still lets the requester read what came in before it expired', function () {
    [$request, $password, , $guest, $requester] = fillableRequest();

    SecretValue::record($password, 'geheim', $guest);

    $request->forceFill(['expires_at' => now()->subDay()])->save();

    actingAs($requester)
        ->post(route('secrets.reveal', [$request, $password]))
        ->assertOk()
        ->assertJson(['value' => 'geheim']);
});

it('keeps one requester out of another requester answers', function () {
    [$request, $password, , $guest] = fillableRequest();
    SecretValue::record($password, 'geheim', $guest);

    [, , , , $otherRequester] = fillableRequest();

    actingAs($otherRequester)
        ->get(route('secrets.answers', $request))
        ->assertForbidden();
});
