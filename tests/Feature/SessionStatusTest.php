<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('answers 204 while the member is signed in', function () {
    actingAs(User::factory()->create())
        ->get(route('session.status'))
        ->assertNoContent();
});

/**
 * A 401 rather than a redirect to the login page: the browser check cannot tell
 * a redirect apart from a healthy response, so putting this behind the "auth"
 * middleware would make it always look fine.
 */
it('answers 401 once the session is gone', function () {
    $this->get(route('session.status'))
        ->assertUnauthorized()
        ->assertNoContent(401);
});

it('carries no body worth parsing', function () {
    actingAs(User::factory()->create());

    expect($this->get(route('session.status'))->getContent())->toBe('');
});
