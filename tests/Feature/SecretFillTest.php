<?php

use App\Models\SecretValue;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows the customer what is being asked, and nothing more', function () {
    [$request, , , $guest] = fillableRequest();

    actingAs($guest)
        ->get(route('secrets.show', $request))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('secrets/fill')
            ->where('request.state', 'open')
            ->has('request.keys', 2)
            ->where('request.keys.0.name', 'DB_PASSWORD')
            ->where('request.keys.0.isAnswered', false)
        );
});

it('takes an answer and encrypts it', function () {
    [$request, $password, , $guest] = fillableRequest();

    actingAs($guest)
        ->post(route('secrets.fill', $request), [
            'values' => [$password->id => 'hunter2-geheim'],
        ])
        ->assertRedirect();

    $value = SecretValue::sole();

    expect($value->filled_by)->toBe($guest->id)
        ->and($value->reveal())->toBe('hunter2-geheim');

    // Encrypted on the way in, not merely hidden on the way out.
    expect(DB::table('secret_values')->value('value'))->not->toContain('hunter2');
});

/**
 * The sentence the whole feature turns on: fill it in once and never see it
 * again. The response to the very request that submitted it is the first place
 * that could leak, and the easiest one to leak from by accident.
 */
it('says nothing about the value in the response that took it', function () {
    [$request, $password, , $guest] = fillableRequest();

    $response = actingAs($guest)->post(route('secrets.fill', $request), [
        'values' => [$password->id => 'hunter2-geheim'],
    ]);

    expect($response->getContent())->not->toContain('hunter2-geheim');
    expect(session()->all())->not->toContain('hunter2-geheim');
});

it('does not show it back on the form afterwards either', function () {
    [$request, $password, , $guest] = fillableRequest();

    actingAs($guest)->post(route('secrets.fill', $request), [
        'values' => [$password->id => 'hunter2-geheim'],
    ]);

    $response = actingAs($guest)->get(route('secrets.show', $request))->assertOk();

    expect($response->getContent())->not->toContain('hunter2-geheim');

    $response->assertInertia(fn ($page) => $page
        ->where('request.keys.0.isAnswered', true)
        // Answered is all anybody gets: there is no value in the payload to
        // find, whatever the page decides to draw.
        ->missing('request.keys.0.value')
    );
});

/** Enforced by the database inside a transaction, not by a check beforehand. */
it('refuses a second answer to the same key', function () {
    [$request, $password, , $guest] = fillableRequest();

    actingAs($guest)->post(route('secrets.fill', $request), [
        'values' => [$password->id => 'eerste'],
    ])->assertRedirect();

    // A second person, so it is not the same session being blocked.
    [$other] = fillableRequest();

    actingAs($guest)->post(route('secrets.fill', $request), [
        'values' => [$password->id => 'tweede'],
    ])->assertRedirect();

    expect(SecretValue::where('secret_request_key_id', $password->id)->count())->toBe(1)
        ->and(SecretValue::where('secret_request_key_id', $password->id)->sole()->reveal())
        ->toBe('eerste');

    expect($other)->not->toBeNull();
});

it('takes several keys at once and leaves the empty ones alone', function () {
    [$request, $password, $token, $guest] = fillableRequest();

    actingAs($guest)
        ->post(route('secrets.fill', $request), [
            'values' => [
                $password->id => 'geheim',
                $token->id => '   ',
            ],
        ])
        ->assertRedirect();

    expect(SecretValue::count())->toBe(1)
        ->and($token->refresh()->isAnswered())->toBeFalse();
});

it('refuses an answer once the request has closed', function (array $state) {
    [$request, $password, , $guest] = fillableRequest($state);

    actingAs($guest)
        ->post(route('secrets.fill', $request), [
            'values' => [$password->id => 'te laat'],
        ])
        ->assertForbidden();

    expect(SecretValue::count())->toBe(0);
})->with([
    'expired' => [['expires_at' => now()->subDay()]],
    'withdrawn' => [['revoked_at' => now()->subHour()]],
]);

/** A closed request still renders, or the link in the channel is a bare 404. */
it('still opens a closed request, to say why it is closed', function () {
    [$request, , , $guest] = fillableRequest(['revoked_at' => now()]);

    actingAs($guest)
        ->get(route('secrets.show', $request))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('request.state', 'revoked'));
});

it('is nothing at all to somebody who cannot see the channel', function () {
    [$request, $password] = fillableRequest();

    $outsider = User::factory()->create();

    actingAs($outsider)->get(route('secrets.show', $request))->assertForbidden();

    actingAs($outsider)
        ->post(route('secrets.fill', $request), ['values' => [$password->id => 'x']])
        ->assertForbidden();
});

/** A key from another request must not resolve through this one. */
it('ignores a key that belongs to another request', function () {
    [$request, , , $guest] = fillableRequest();
    [, $strangerKey] = fillableRequest();

    actingAs($guest)
        ->post(route('secrets.fill', $request), [
            'values' => [$strangerKey->id => 'geheim'],
        ])
        ->assertRedirect();

    expect(SecretValue::count())->toBe(0);
});

/**
 * The person who asked never fills their own form. Sending them there would be
 * a dead end with a "0 van 2 ingevuld" they cannot act on, so the same link
 * takes them to what came in instead.
 */
it('sends the person who asked to the answers instead of the form', function () {
    [$request, , , , $requester] = fillableRequest();

    actingAs($requester)
        ->get(route('secrets.show', $request))
        ->assertRedirect(route('secrets.answers', $request, absolute: false));
});

it('sends everybody else to the form', function () {
    [$request, , , $guest] = fillableRequest();

    actingAs($guest)
        ->get(route('secrets.show', $request))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('secrets/fill'));
});
