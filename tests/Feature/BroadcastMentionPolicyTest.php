<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
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

it('defaults to whoever runs the workspace', function () {
    $workspace = Workspace::factory()->create();

    $holders = $workspace->roles()->get()
        ->filter(fn ($role) => $role->allows(WorkspaceAbility::BroadcastMention))
        ->pluck('key');

    expect($holders->all())->toBe(['owner', 'admin']);
});

it('lets everyone broadcast when the workspace is open', function (SystemRole $role) {
    [$user, $workspace] = workspaceWithRole($role);
    setAbility($workspace, WorkspaceAbility::BroadcastMention, true);

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
    setAbility($workspace, WorkspaceAbility::BroadcastMention, false);

    expect($user->can('broadcastMention', $workspace))->toBeFalse();
})->with([
    'eigenaar' => SystemRole::Owner,
    'beheerder' => SystemRole::Admin,
    'lid' => SystemRole::Member,
]);

it('never lets an outsider broadcast, however open the workspace is', function () {
    $outsider = User::factory()->create();
    $workspace = Workspace::factory()->create();

    setAbility($workspace, WorkspaceAbility::BroadcastMention, true);

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
