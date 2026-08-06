<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * Setting up a platform that has never been set up.
 *
 * Two things are being checked here and the second matters more than the first.
 * That the screen works, and that it stops existing — it hands out moderator
 * rights over every account and every message on the platform to an anonymous
 * request, so the window in which it answers at all has to close on its own and
 * stay closed.
 */
function installationForm(array $overrides = []): array
{
    return [
        'name' => 'Sanne Bakker',
        'email' => 'sanne@voorbeeld.nl',
        'password' => 'Wachtwoord!2026',
        'password_confirmation' => 'Wachtwoord!2026',
        'workspace' => 'De Werkplaats',
        ...$overrides,
    ];
}

it('shows the onboarding screen on a platform with nothing in it', function () {
    get(route('install.show'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('install/welcome')
            // What the browser should ask of the password before the server
            // refuses it, read off Password::defaults().
            ->has('passwordRules'));
});

it('sends the front door to the onboarding screen', function (string $route) {
    get(route($route))->assertRedirect(route('install.show'));
})->with(['home', 'login', 'register']);

it('makes the first account a moderator and gives it a workspace', function () {
    post(route('install.store'), installationForm())
        ->assertRedirect(route('chat.home'));

    $user = User::firstWhere('email', 'sanne@voorbeeld.nl');

    expect($user)->not->toBeNull()
        ->and($user->isAdmin())->toBeTrue()
        // Nothing was mailed to prove the address, and on a fresh install
        // nothing could be: leaving it unverified would lock the platform
        // behind its own verification screen. See InstallApplication.
        ->and($user->hasVerifiedEmail())->toBeTrue()
        // Signed in on the way out, so the redirect lands somewhere rather
        // than bouncing back to a login screen that no longer exists.
        ->and(auth()->id())->toBe($user->id);

    $workspace = Workspace::firstWhere('name', 'De Werkplaats');

    expect($workspace)->not->toBeNull()
        ->and($workspace->owner_id)->toBe($user->id)
        // A workspace with no channel used to answer 404 at its own door.
        ->and($workspace->channels()->count())->toBeGreaterThan(0);
});

it('closes the door behind itself', function () {
    post(route('install.store'), installationForm());

    get(route('install.show'))->assertNotFound();
    post(route('install.store'), installationForm(['email' => 'iemand@voorbeeld.nl']))
        ->assertNotFound();
});

/**
 * The POST as much as the GET. Hiding a form is presentation; this is the part
 * that decides whether a stranger can still conjure a platform-wide moderator
 * out of an anonymous request.
 */
it('refuses the endpoint once anybody at all has an account', function () {
    User::factory()->create();

    get(route('install.show'))->assertNotFound();
    post(route('install.store'), installationForm())->assertNotFound();

    expect(User::firstWhere('email', 'sanne@voorbeeld.nl'))->toBeNull();
});

it('does not take over the front door once the platform exists', function (string $route) {
    User::factory()->create();

    get(route($route))->assertOk();
})->with(['home', 'login']);

it('leaves the rest of the public site alone while it waits', function () {
    // Deliberately not redirected: robots.txt is fetched by something that has
    // no idea what a 302 to an onboarding screen means, and a platform being
    // empty is no reason to lie to it. See RedirectToInstallation.
    get(route('robots'))->assertOk();
});

it('writes nothing at all when the form is wrong', function () {
    post(route('install.store'), installationForm(['workspace' => '']))
        ->assertSessionHasErrors('workspace');

    expect(User::count())->toBe(0)
        ->and(Workspace::count())->toBe(0);
});

/**
 * A double-submitted form is the realistic way two moderators get made, and the
 * unique rule on the address is what stops it: the second one fails validation
 * rather than quietly appointing somebody a second time.
 */
it('refuses a second submission of the same form', function () {
    post(route('install.store'), installationForm());
    post(route('install.store'), installationForm())->assertNotFound();

    expect(User::count())->toBe(1)
        ->and(Workspace::count())->toBe(1);
});
