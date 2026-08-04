<?php

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\post;

function closeRegistration(): void
{
    Config::set('auth.registration_open', false);
}

it('offers the sign-up page while the door is open', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/register'));
});

it('stops answering once registration is closed', function () {
    closeRegistration();

    // A 404 rather than a 403: an installation that has shut the door would
    // usually rather not say what is behind it.
    $this->get(route('register'))->assertNotFound();
});

it('refuses to create an account over the endpoint as well', function () {
    closeRegistration();

    post(route('register.store'), [
        'name' => 'Fenna de Vries',
        'email' => 'fenna@example.test',
        'password' => 'wachtwoord-met-genoeg-lengte',
        'password_confirmation' => 'wachtwoord-met-genoeg-lengte',
    ])->assertNotFound();

    // Hiding the form is presentation; this is the part that decides whether
    // an account can exist.
    expect(User::where('email', 'fenna@example.test')->exists())->toBeFalse();
});

it('tells the login screen not to offer a way in', function () {
    closeRegistration();

    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('registrationOpen', false));
});

it('still lets an invited person make an account', function () {
    closeRegistration();

    $inviter = User::factory()->create();
    $workspace = workspaceWithMember($inviter);

    $invitation = Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'fenna@example.test',
        'token' => Str::random(40),
        'invited_by' => $inviter->id,
        'expires_at' => now()->addWeek(),
    ]);

    /*
     * The whole point of the switch. Closing open registration shuts one door;
     * an invitation is somebody being let in by name, and it creates its own
     * account without going near Fortify.
     */
    post(route('invitations.accept', $invitation->token), [
        'name' => 'Fenna de Vries',
        'password' => 'wachtwoord-met-genoeg-lengte',
        'password_confirmation' => 'wachtwoord-met-genoeg-lengte',
    ])->assertRedirect();

    expect(User::where('email', 'fenna@example.test')->exists())->toBeTrue();
});
