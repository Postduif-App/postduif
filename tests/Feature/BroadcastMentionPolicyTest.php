<?php

use App\Enums\BroadcastMentionPolicy;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * @return array{0: User, 1: Workspace}
 */
function workspaceWithRole(WorkspaceRole $role): array
{
    $user = User::factory()->create();

    return [$user, workspaceWithMember($user, $role)];
}

it('defaults to admins only', function () {
    expect(Workspace::factory()->create()->broadcast_mentions)
        ->toBe(BroadcastMentionPolicy::Admins);
});

it('lets everyone broadcast when the workspace is open', function (WorkspaceRole $role) {
    [$user, $workspace] = workspaceWithRole($role);
    $workspace->update(['broadcast_mentions' => BroadcastMentionPolicy::Everyone]);

    expect($user->can('broadcastMention', $workspace))->toBeTrue();
})->with([
    'eigenaar' => WorkspaceRole::Owner,
    'beheerder' => WorkspaceRole::Admin,
    'lid' => WorkspaceRole::Member,
]);

it('limits broadcasting to who runs the workspace by default', function (WorkspaceRole $role, bool $allowed) {
    [$user, $workspace] = workspaceWithRole($role);

    expect($user->can('broadcastMention', $workspace))->toBe($allowed);
})->with([
    'eigenaar mag' => [WorkspaceRole::Owner, true],
    'beheerder mag' => [WorkspaceRole::Admin, true],
    'lid mag niet' => [WorkspaceRole::Member, false],
]);

it('lets nobody broadcast when the workspace is closed', function (WorkspaceRole $role) {
    [$user, $workspace] = workspaceWithRole($role);
    $workspace->update(['broadcast_mentions' => BroadcastMentionPolicy::Nobody]);

    expect($user->can('broadcastMention', $workspace))->toBeFalse();
})->with([
    'eigenaar' => WorkspaceRole::Owner,
    'beheerder' => WorkspaceRole::Admin,
    'lid' => WorkspaceRole::Member,
]);

it('never lets an outsider broadcast, however open the workspace is', function () {
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'broadcast_mentions' => BroadcastMentionPolicy::Everyone,
    ]);

    expect($outsider->can('broadcastMention', $workspace))->toBeFalse();
});

it('only lets an admin or owner change workspace settings', function (WorkspaceRole $role, bool $allowed) {
    [$user, $workspace] = workspaceWithRole($role);

    expect($user->can('manage', $workspace))->toBe($allowed);
})->with([
    'eigenaar' => [WorkspaceRole::Owner, true],
    'beheerder' => [WorkspaceRole::Admin, true],
    'lid' => [WorkspaceRole::Member, false],
]);
