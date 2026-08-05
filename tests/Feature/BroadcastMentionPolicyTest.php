<?php

use App\Enums\BroadcastMentionPolicy;
use App\Enums\SystemRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * @return array{0: User, 1: Workspace}
 */
function workspaceWithRole(SystemRole $role): array
{
    $user = User::factory()->create();

    return [$user, workspaceWithMember($user, $role)];
}

it('defaults to admins only', function () {
    expect(Workspace::factory()->create()->broadcast_mentions)
        ->toBe(BroadcastMentionPolicy::Admins);
});

it('lets everyone broadcast when the workspace is open', function (SystemRole $role) {
    [$user, $workspace] = workspaceWithRole($role);
    $workspace->update(['broadcast_mentions' => BroadcastMentionPolicy::Everyone]);

    expect($user->can('broadcastMention', $workspace))->toBeTrue();
})->with([
    'eigenaar' => SystemRole::Owner,
    'beheerder' => SystemRole::Admin,
    'lid' => SystemRole::Member,
]);

it('limits broadcasting to who runs the workspace by default', function (SystemRole $role, bool $allowed) {
    [$user, $workspace] = workspaceWithRole($role);

    expect($user->can('broadcastMention', $workspace))->toBe($allowed);
})->with([
    'eigenaar mag' => [SystemRole::Owner, true],
    'beheerder mag' => [SystemRole::Admin, true],
    'lid mag niet' => [SystemRole::Member, false],
]);

it('lets nobody broadcast when the workspace is closed', function (SystemRole $role) {
    [$user, $workspace] = workspaceWithRole($role);
    $workspace->update(['broadcast_mentions' => BroadcastMentionPolicy::Nobody]);

    expect($user->can('broadcastMention', $workspace))->toBeFalse();
})->with([
    'eigenaar' => SystemRole::Owner,
    'beheerder' => SystemRole::Admin,
    'lid' => SystemRole::Member,
]);

it('never lets an outsider broadcast, however open the workspace is', function () {
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'broadcast_mentions' => BroadcastMentionPolicy::Everyone,
    ]);

    expect($outsider->can('broadcastMention', $workspace))->toBeFalse();
});

it('only lets an admin or owner change workspace settings', function (SystemRole $role, bool $allowed) {
    [$user, $workspace] = workspaceWithRole($role);

    expect($user->can('manage', $workspace))->toBe($allowed);
})->with([
    'eigenaar' => [SystemRole::Owner, true],
    'beheerder' => [SystemRole::Admin, true],
    'lid' => [SystemRole::Member, false],
]);
