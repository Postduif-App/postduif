<?php

use App\Enums\SystemRole;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\RelationManagers\WorkspacesRelationManager;
use App\Filament\Resources\Workspaces\Pages\EditWorkspace;
use App\Filament\Resources\Workspaces\RelationManagers\MembersRelationManager;
use App\Models\User;
use App\Models\Workspace;
use Livewire\Livewire;

/**
 * Putting somebody in a second workspace.
 *
 * The pivot has always allowed it and the sidebar has always drawn a switcher
 * for it, but there was no way to make it happen: the workspace's member list
 * still wrote a `role` column that a migration dropped, and the user page had
 * no workspaces at all. So the tests here are about both ends of the same row.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('offers the workspaces on the page a moderator is already looking at', function () {
    $member = User::factory()->create();

    // The relation manager is only useful if it is actually mounted on the
    // page; the tests below drive the component in isolation and would pass
    // either way.
    $this->get("/admin/users/{$member->getKey()}/edit")
        ->assertSuccessful()
        ->assertSeeText('Workspaces');
});

it('adds a member to a second workspace from their own page', function () {
    $member = User::factory()->create();
    $first = workspaceWithMember($member);
    $second = Workspace::factory()->create();

    Livewire::test(WorkspacesRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditUser::class,
    ])
        ->assertCanSeeTableRecords([$first])
        ->callTableAction('attach', data: [
            'recordId' => $second->id,
            'workspace_role_id' => roleId($second, SystemRole::Member),
        ])
        ->assertHasNoTableActionErrors();

    expect($member->workspaces()->pluck('workspaces.id')->all())
        ->toEqualCanonicalizing([$first->id, $second->id])
        ->and($second->roleFor($member)?->key)->toBe(SystemRole::Member->value);
});

it('gives the membership a moment it started', function () {
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create();

    Livewire::test(WorkspacesRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => $workspace->id,
            'workspace_role_id' => roleId($workspace, SystemRole::Member),
        ]);

    expect($member->workspaces()->sole()->membership->joined_at)->not->toBeNull();
});

it('changes what somebody is in one workspace without touching the other', function () {
    $member = User::factory()->create();
    $first = workspaceWithMember($member);
    $second = Workspace::factory()->create();
    joinWorkspace($second, $member);

    Livewire::test(WorkspacesRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('edit', $second, data: [
            'workspace_role_id' => roleId($second, SystemRole::Admin),
        ])
        ->assertHasNoTableActionErrors();

    expect($second->roleFor($member)?->key)->toBe(SystemRole::Admin->value)
        // A role is a row belonging to one workspace, so this is not a setting
        // that can leak sideways — which is exactly why it is worth asserting.
        ->and($first->roleFor($member)?->key)->toBe(SystemRole::Member->value);
});

it('will not unhook somebody from a workspace they own', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
    joinWorkspace($workspace, $owner, SystemRole::Owner);

    Livewire::test(WorkspacesRelationManager::class, [
        'ownerRecord' => $owner,
        'pageClass' => EditUser::class,
    ])
        ->assertTableActionDisabled('detach', $workspace);
});

it('unhooks somebody from a workspace they merely belong to', function () {
    $member = User::factory()->create();
    $workspace = workspaceWithMember($member);

    Livewire::test(WorkspacesRelationManager::class, [
        'ownerRecord' => $member,
        'pageClass' => EditUser::class,
    ])
        ->callTableAction('detach', $workspace);

    expect($workspace->refresh()->hasMember($member))->toBeFalse();
});

/**
 * The same row, written from the workspace's page.
 *
 * This is the one that was broken: the attach form filled a `role` column that
 * 2026_08_05_141107_drop_role_from_workspace_user_table took away, so the
 * button answered with a database error.
 */
it('adds a member from the workspace page', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->callTableAction('attach', data: [
            'recordId' => $member->id,
            'workspace_role_id' => roleId($workspace, SystemRole::Admin),
        ])
        ->assertHasNoTableActionErrors();

    expect($workspace->refresh()->hasMember($member))->toBeTrue()
        ->and($workspace->roleFor($member)?->key)->toBe(SystemRole::Admin->value);
});

it('changes a members role from the workspace page', function () {
    $workspace = Workspace::factory()->create();
    $member = User::factory()->create();
    joinWorkspace($workspace, $member);

    Livewire::test(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => EditWorkspace::class,
    ])
        ->callTableAction('edit', $member, data: [
            'workspace_role_id' => roleId($workspace, SystemRole::Admin),
        ])
        ->assertHasNoTableActionErrors();

    // fresh(), because roleFor() answers from a per-instance cache the table
    // render above has already warmed.
    expect($workspace->fresh()->roleFor($member)?->key)->toBe(SystemRole::Admin->value);
});

/*
 * What all of this is for — a second membership showing up in the sidebar
 * switcher — is already covered by WorkspaceSwitcherTest.
 */
