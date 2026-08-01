<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('sends a suspended member to the login screen instead of the app', function () {
    $user = User::factory()->suspended()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->get(route('chat.index', $workspace))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('leaves an ordinary member alone', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertSuccessful();

    $this->assertAuthenticated();
});

/**
 * The lever lands while they are already signed in, which is the case that a
 * check at the login screen alone would miss entirely.
 */
it('cuts off a member who is suspended mid-session', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)->get(route('chat.show', [$workspace, $channel]))->assertSuccessful();

    $user->forceFill(['suspended_at' => now()])->save();

    $this->get(route('chat.show', [$workspace, $channel]))
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
});

it('will not let a suspended member post', function () {
    $user = User::factory()->suspended()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), [
            'id' => strtolower((string) Str::ulid()),
            'body' => 'Ik ben er nog',
        ])
        ->assertRedirect(route('login', absolute: false));

    expect(Message::count())->toBe(0);
});

it('does not let a suspended member sign in again', function () {
    $user = User::factory()->suspended()->create();
    $workspace = workspaceWithMember($user);

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->get(route('chat.index', $workspace))
        ->assertRedirect(route('login', absolute: false));

    $this->assertGuest();
});

/**
 * The tab a suspended member left open polls session-status over fetch, and only
 * a 401 tells it to leave. A redirect to the login page would look healthy.
 */
it('answers the session check with a 401 for a suspended member', function () {
    actingAs(User::factory()->suspended()->create())
        ->get(route('session.status'), ['Accept' => 'application/json'])
        ->assertUnauthorized();
});

it('shuts a suspended moderator out of the admin panel', function () {
    actingAs(User::factory()->admin()->suspended()->create())
        ->get('/admin')
        ->assertForbidden();
});

it('knows who is suspended', function () {
    expect(User::factory()->create()->isSuspended())->toBeFalse()
        ->and(User::factory()->suspended()->create()->isSuspended())->toBeTrue();
});
