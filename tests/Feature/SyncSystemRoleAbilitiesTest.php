<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Workspace;

/**
 * Catching up the roles a workspace was born with.
 *
 * The test that carries this file is the last one: a role a workspace wrote for
 * itself is never touched. Everything above it is about closing a gap; that one
 * is about not walking into somebody else's decisions while doing it.
 */
it('gives every owner role the rights that were added after it was made', function () {
    $workspace = Workspace::factory()->create();

    $owner = $workspace->roles()->where('key', SystemRole::Owner->value)->firstOrFail();
    $owner->forceFill(['abilities' => [WorkspaceAbility::ManageWorkspace->value]])->save();

    $this->artisan('workspaces:sync-role-abilities')->assertSuccessful();

    expect($owner->refresh()->abilities)->toBe(WorkspaceAbility::values());
});

it('leaves the other system roles alone unless it is asked', function () {
    $workspace = Workspace::factory()->create();

    $admin = $workspace->roles()->where('key', SystemRole::Admin->value)->firstOrFail();
    $admin->forceFill(['abilities' => []])->save();

    $this->artisan('workspaces:sync-role-abilities')->assertSuccessful();

    expect($admin->refresh()->abilities)->toBe([]);
});

it('tops the other system roles up from their defaults when asked', function () {
    $workspace = Workspace::factory()->create();

    $admin = $workspace->roles()->where('key', SystemRole::Admin->value)->firstOrFail();
    $admin->forceFill(['abilities' => []])->save();

    $this->artisan('workspaces:sync-role-abilities', ['--system-roles' => true])->assertSuccessful();

    expect($admin->refresh()->allows(WorkspaceAbility::InviteMembers))->toBeTrue();
});

it('keeps a right an administrator was given by hand', function () {
    $workspace = Workspace::factory()->create();

    $admin = $workspace->roles()->where('key', SystemRole::Admin->value)->firstOrFail();

    /*
     * SeeHours is off in the defaults for every role but the owner, so a role
     * holding it can only have been given it on purpose. Topping up must add,
     * never replace.
     */
    $admin->forceFill(['abilities' => [WorkspaceAbility::SeeHours->value]])->save();

    $this->artisan('workspaces:sync-role-abilities', ['--system-roles' => true])->assertSuccessful();

    expect($admin->refresh()->allows(WorkspaceAbility::SeeHours))->toBeTrue();
});

it('writes nothing on a dry run', function () {
    $workspace = Workspace::factory()->create();

    $owner = $workspace->roles()->where('key', SystemRole::Owner->value)->firstOrFail();
    $owner->forceFill(['abilities' => []])->save();

    $this->artisan('workspaces:sync-role-abilities', ['--dry-run' => true])->assertSuccessful();

    expect($owner->refresh()->abilities)->toBe([]);
});

it('changes nothing when everything is already in place', function () {
    Workspace::factory()->create();

    $this->artisan('workspaces:sync-role-abilities')
        ->expectsOutputToContain(__('console.role_abilities_in_sync'))
        ->assertSuccessful();
});

it('never touches a role a workspace wrote for itself', function () {
    $workspace = Workspace::factory()->create();

    $invented = $workspace->roles()->create([
        'key' => 'leverancier',
        'name' => 'Leverancier',
        'is_system' => false,
        'position' => 9,
        'abilities' => [WorkspaceAbility::SeeMembers->value],
    ]);

    $this->artisan('workspaces:sync-role-abilities', ['--system-roles' => true])->assertSuccessful();

    expect($invented->refresh()->abilities)->toBe([WorkspaceAbility::SeeMembers->value]);
});
