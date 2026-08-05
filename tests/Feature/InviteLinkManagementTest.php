<?php

use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\InviteLink;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Making and withdrawing links. Following one is the other half, and lives in
 * its own file: it is reachable while signed out, which is a different world.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function workspaceHandingOutLinks(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    $workspace->forceFill(['owner_id' => $owner->id])->save();

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
    ]);

    return [$owner, $workspace, $channel];
}

it('makes a link with no limits on it', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();

    actingAs($owner)
        ->post(route('chat.invite-links.store', $workspace), [
            'role' => roleId($workspace, SystemRole::Member),
        ])
        ->assertRedirect();

    $link = InviteLink::sole();

    expect($link->workspaceRole->key)->toBe(SystemRole::Member->value)
        ->and($link->max_uses)->toBeNull()
        ->and($link->expires_at)->toBeNull()
        ->and($link->uses)->toBe(0)
        ->and($link->created_by)->toBe($owner->id)
        ->and($link->token)->toHaveLength(64)
        ->and($link->isUsable())->toBeTrue();
});

it('makes a link with a ceiling and a date', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();

    actingAs($owner)->post(route('chat.invite-links.store', $workspace), [
        'role' => roleId($workspace, SystemRole::Member),
        'max_uses' => 5,
        'valid_for_days' => 7,
    ]);

    $link = InviteLink::sole();

    expect($link->max_uses)->toBe(5)
        ->and($link->expires_at->isSameDay(now()->addDays(7)))->toBeTrue();
});

it('puts the chosen channels on the link', function () {
    [$owner, $workspace, $channel] = workspaceHandingOutLinks();

    actingAs($owner)->post(route('chat.invite-links.store', $workspace), [
        'role' => roleId($workspace, SystemRole::Guest),
        'channel_ids' => [$channel->id],
    ]);

    expect(InviteLink::sole()->channels->pluck('id')->all())->toBe([$channel->id]);
});

it('refuses a guest link with no channels on it', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();

    actingAs($owner)
        ->post(route('chat.invite-links.store', $workspace), [
            'role' => roleId($workspace, SystemRole::Guest),
        ])
        ->assertSessionHasErrors('channel_ids');

    expect(InviteLink::count())->toBe(0);
});

it('drops channels that are not this workspace to hand out', function () {
    [$owner, $workspace, $channel] = workspaceHandingOutLinks();

    $elsewhere = Channel::factory()->create();
    $archived = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'archived_at' => now(),
    ]);

    actingAs($owner)->post(route('chat.invite-links.store', $workspace), [
        'role' => roleId($workspace, SystemRole::Guest),
        'channel_ids' => [$channel->id, $elsewhere->id, $archived->id],
    ]);

    expect(InviteLink::sole()->channels->pluck('id')->all())->toBe([$channel->id]);
});

it('refuses somebody who may not invite', function () {
    [, $workspace] = workspaceHandingOutLinks();

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => SystemRole::Guest->value,
        'joined_at' => now(),
    ]);

    actingAs($guest)
        ->post(route('chat.invite-links.store', $workspace), [
            'role' => roleId($workspace, SystemRole::Member),
        ])
        ->assertForbidden();

    expect(InviteLink::count())->toBe(0);
});

it('does not let an admin hand out ownership by link', function () {
    [, $workspace] = workspaceHandingOutLinks();

    $admin = User::factory()->create();
    $workspace->members()->attach($admin->id, [
        'role' => SystemRole::Admin->value,
        'joined_at' => now(),
    ]);

    actingAs($admin)
        ->post(route('chat.invite-links.store', $workspace), [
            'role' => roleId($workspace, SystemRole::Owner),
        ])
        ->assertForbidden();

    expect(InviteLink::count())->toBe(0);
});

it('makes a second link without breaking the first', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();

    actingAs($owner)->post(route('chat.invite-links.store', $workspace), [
        'role' => roleId($workspace, SystemRole::Member),
    ]);
    actingAs($owner)->post(route('chat.invite-links.store', $workspace), [
        'role' => roleId($workspace, SystemRole::Member),
    ]);

    expect(InviteLink::count())->toBe(2)
        ->and(InviteLink::usable()->count())->toBe(2);
});

it('withdraws a link instead of deleting it', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();
    $link = InviteLink::factory()->for($workspace)->create();

    actingAs($owner)
        ->delete(route('chat.invite-links.destroy', [$workspace, $link]))
        ->assertRedirect();

    $link->refresh();

    expect($link->exists)->toBeTrue()
        ->and($link->isRevoked())->toBeTrue()
        ->and($link->isUsable())->toBeFalse();
});

it('leaves the moment of withdrawal alone on a second click', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();
    $link = InviteLink::factory()->for($workspace)->revoked()->create();
    $withdrawnAt = $link->revoked_at;

    actingAs($owner)->delete(route('chat.invite-links.destroy', [$workspace, $link]));

    expect($link->fresh()->revoked_at->equalTo($withdrawnAt))->toBeTrue();
});

it('shows the links on the settings screen, whole URL and all', function () {
    [$owner, $workspace, $channel] = workspaceHandingOutLinks();

    $link = InviteLink::factory()
        ->for($workspace)
        ->guest()
        ->state(['created_by' => $owner->id, 'max_uses' => 5, 'uses' => 2])
        ->create();

    $link->channels()->attach($channel->id);

    InviteLink::factory()->for($workspace)->revoked()->create();

    actingAs($owner)
        ->get(route('workspace.invitations.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/invitations')
            ->has('inviteLinks', 2)
            // Newest first, so the withdrawn one made last leads.
            ->where('inviteLinks.0.state', 'revoked')
            ->where('inviteLinks.1.state', 'usable')
            ->where('inviteLinks.1.url', route('invite-links.show', $link->token))
            ->where('inviteLinks.1.uses', 2)
            ->where('inviteLinks.1.maxUses', 5)
            ->where('inviteLinks.1.channels', [$channel->name])
            ->has('channels', 1));
});

it('does not reach a link in another workspace', function () {
    [$owner, $workspace] = workspaceHandingOutLinks();
    $link = InviteLink::factory()->for(Workspace::factory())->create();

    actingAs($owner)
        ->delete(route('chat.invite-links.destroy', [$workspace, $link]))
        ->assertNotFound();

    expect($link->fresh()->isRevoked())->toBeFalse();
});
