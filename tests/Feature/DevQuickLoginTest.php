<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

use function Pest\Laravel\actingAs;

it('signs in as the chosen account without a password', function () {
    $user = User::factory()->create();

    $this->post(route('dev.login', $user))
        ->assertRedirect(config('fortify.home'));

    expect(auth()->id())->toBe($user->id);
});

it('offers the seeded accounts on the login screen', function () {
    $user = User::factory()->create(['name' => 'Fenna de Vries']);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/login')
            ->has('devAccounts', 1)
            ->where('devAccounts.0.name', 'Fenna de Vries')
            ->where('devAccounts.0.email', $user->email)
        );
});

/**
 * The URL comes from the server rather than from Wayfinder in the browser.
 * routes/dev.php is not registered in production, so a bundle that imported
 * `dev.login` would fail to build there — which is a deployment failure caused
 * entirely by a development convenience.
 */
it('sends the sign-in URL along with each account', function () {
    $user = User::factory()->create();

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('devAccounts.0.url', route('dev.login', $user))
        );
});

it('does not sign in someone who already has a session', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('dev.login', User::factory()->create()))
        ->assertRedirect();

    expect(auth()->id())->toBe($user->id);
});

/**
 * The route is only registered outside production, so it cannot be reached
 * there at all. These two prove the second lock holds anyway, in case the route
 * file ever changes.
 */
it('refuses to sign anyone in when the app is in production', function () {
    $user = User::factory()->create();

    app()->detectEnvironment(fn () => 'production');

    // Pretending to be production also switches CSRF protection back on, which
    // would reject the request with a 419 before the controller runs. Drop that
    // middleware so the assertion is about the guard and nothing else.
    $this->withoutMiddleware(PreventRequestForgery::class)
        ->post(route('dev.login', $user))
        ->assertNotFound();

    expect(auth()->check())->toBeFalse();
});

it('hands out no accounts when the app is in production', function () {
    User::factory()->create();

    app()->detectEnvironment(fn () => 'production');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('devAccounts', 0));
});
