<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\InviteLink;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/**
 * A workspace with a link into it, and a channel on that link.
 *
 * @return array{0: InviteLink, 1: Workspace, 2: Channel}
 */
function workspaceReachableByLink(WorkspaceRole $role = WorkspaceRole::Member): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
    ]);

    $link = InviteLink::factory()
        ->for($workspace)
        ->state(['created_by' => $owner->id, 'role' => $role])
        ->create();

    $link->channels()->attach($channel->id);

    return [$link, $workspace, $channel];
}

it('shows the workspace and its channels to a visitor who is not signed in', function () {
    [$link, $workspace, $channel] = workspaceReachableByLink();

    get(route('invite-links.show', $link->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/join')
            ->where('state', 'usable')
            ->where('mode', 'register')
            ->where('link.workspaceName', $workspace->name)
            ->where('link.channels', [$channel->name]));
});

it('never puts the token in the page payload', function () {
    [$link] = workspaceReachableByLink();

    get(route('invite-links.show', $link->token))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('link.token'));
});

it('makes an account and joins in one go', function () {
    [$link, $workspace, $channel] = workspaceReachableByLink();

    post(route('invite-links.join', $link->token), [
        'name' => 'Nieuwe Collega',
        'email' => 'nieuw@voorbeeld.nl',
        'password' => 'sterk-wachtwoord-123',
        'password_confirmation' => 'sterk-wachtwoord-123',
    ])->assertRedirect(route('chat.index', $workspace));

    $user = User::where('email', 'nieuw@voorbeeld.nl')->sole();

    expect($workspace->roleFor($user))->toBe(WorkspaceRole::Member)
        ->and($channel->members()->whereKey($user->id)->exists())->toBeTrue()
        ->and($link->fresh()->uses)->toBe(1)
        // Nothing proved the address, unlike a mailed invitation.
        ->and($user->email_verified_at)->toBeNull();
});

it('joins somebody who is already signed in', function () {
    [$link, $workspace, $channel] = workspaceReachableByLink();
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('invite-links.join', $link->token))
        ->assertRedirect(route('chat.index', $workspace));

    expect($workspace->roleFor($user))->toBe(WorkspaceRole::Member)
        ->and($channel->members()->whereKey($user->id)->exists())->toBeTrue()
        ->and($link->fresh()->uses)->toBe(1);
});

it('joins as a guest when the link says so', function () {
    [$link, $workspace] = workspaceReachableByLink(WorkspaceRole::Guest);
    $user = User::factory()->create();

    actingAs($user)->post(route('invite-links.join', $link->token));

    expect($workspace->roleFor($user))->toBe(WorkspaceRole::Guest);
});

it('leaves an existing member where they are and spends no use', function () {
    [$link, $workspace, $channel] = workspaceReachableByLink(WorkspaceRole::Guest);

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => WorkspaceRole::Admin->value,
        'joined_at' => now(),
    ]);

    actingAs($admin)->post(route('invite-links.join', $link->token));

    expect($workspace->roleFor($admin))->toBe(WorkspaceRole::Admin)
        // The channels do get added: that is a reasonable thing to follow a
        // link for, and it costs nobody a place.
        ->and($channel->members()->whereKey($admin->id)->exists())->toBeTrue()
        ->and($link->fresh()->uses)->toBe(0);
});

it('sends somebody who signs in back to the link', function () {
    [$link] = workspaceReachableByLink();

    get(route('invite-links.show', $link->token));

    expect(session('url.intended'))->toBe(route('invite-links.show', $link->token));
});

it('does not move the intended url for somebody already signed in', function () {
    [$link] = workspaceReachableByLink();

    actingAs(User::factory()->create())->get(route('invite-links.show', $link->token));

    expect(session('url.intended'))->toBeNull();
});

it('names the reason a link stopped working', function (string $state, callable $make) {
    $workspace = Workspace::factory()->create();
    $link = $make(InviteLink::factory()->for($workspace))->create();

    get(route('invite-links.show', $link->token))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', $state)
            ->where('mode', 'none'));
})->with([
    'expired' => ['expired', fn ($factory) => $factory->expired()],
    'revoked' => ['revoked', fn ($factory) => $factory->revoked()],
    'exhausted' => ['exhausted', fn ($factory) => $factory->exhausted()],
]);

it('answers an unknown token with a page rather than a dead end', function () {
    get(route('invite-links.show', 'niet-bestaand'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('state', 'unknown')
            ->where('link', null));
});

it('refuses to be used once it is spent', function () {
    $workspace = Workspace::factory()->create();
    $link = InviteLink::factory()->for($workspace)->exhausted()->create();
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('invite-links.join', $link->token))
        ->assertGone();

    expect($workspace->hasMember($user))->toBeFalse();
});

it('does not let a limited link past its maximum', function () {
    $workspace = Workspace::factory()->create();
    $link = InviteLink::factory()->for($workspace)->state(['max_uses' => 1])->create();

    actingAs(User::factory()->create())->post(route('invite-links.join', $link->token));

    $second = User::factory()->create();

    actingAs($second)
        ->post(route('invite-links.join', $link->token))
        ->assertGone();

    expect($link->fresh()->uses)->toBe(1)
        ->and($workspace->hasMember($second))->toBeFalse();
});

it('refuses an address that already has an account', function () {
    [$link] = workspaceReachableByLink();
    $existing = User::factory()->create();

    post(route('invite-links.join', $link->token), [
        'name' => 'Zelfde Adres',
        'email' => $existing->email,
        'password' => 'sterk-wachtwoord-123',
        'password_confirmation' => 'sterk-wachtwoord-123',
    ])->assertSessionHasErrors('email');

    expect($link->fresh()->uses)->toBe(0);
});
