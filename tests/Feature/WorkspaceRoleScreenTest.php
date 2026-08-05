<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/** Somebody who runs a workspace, and the workspace. */
function roleManager(SystemRole $role = SystemRole::Owner): array
{
    $user = User::factory()->create();

    return [$user, workspaceWithMember($user, $role)];
}

it('shows every role with what it may do', function () {
    [$owner, $workspace] = roleManager();

    actingAs($owner)
        ->get(route('workspace.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace-roles')
            ->has('roles', 4)
            ->where('roles.0.key', SystemRole::Owner->value)
            ->where('roles.0.isSystem', true)
            ->where('roles.3.isExternal', true)
            // The whole catalogue, so somebody can see what they are not
            // holding as well as what they are.
            ->has('abilities', count(WorkspaceAbility::cases()))
        );
});

it('keeps the screen away from somebody who does not run the place', function () {
    [$member] = roleManager(SystemRole::Member);

    actingAs($member)->get(route('workspace.roles.index'))->assertForbidden();
});

it('writes a role of the workspace own making', function () {
    [$owner, $workspace] = roleManager();

    actingAs($owner)
        ->post(route('workspace.roles.store'), [
            'name' => 'Leverancier',
            'is_external' => true,
            'abilities' => [WorkspaceAbility::SeeMembers->value],
        ])
        ->assertRedirect();

    $role = $workspace->roles()->where('name', 'Leverancier')->first();

    expect($role)->not->toBeNull()
        ->and($role->is_external)->toBeTrue()
        ->and($role->is_system)->toBeFalse()
        // At the bottom: standing decides who may touch whom, so a new role
        // starting anywhere else would hand out seniority nobody asked for.
        ->and($role->position)->toBeGreaterThan(
            $workspace->roles()->where('key', SystemRole::Guest->value)->value('position')
        );
});

it('will not write a right the author does not hold', function () {
    [$admin, $workspace] = roleManager(SystemRole::Admin);

    /*
     * Everything except the one they are about to try to hand out. Managing
     * the workspace stays, or they could not open this screen at all — which
     * is a different refusal from the one under test.
     */
    $workspace->roles()
        ->where('key', SystemRole::Admin->value)
        ->first()
        ->update(['abilities' => [
            WorkspaceAbility::ManageWorkspace->value,
            WorkspaceAbility::SeeMembers->value,
        ]]);

    actingAs($admin)
        ->post(route('workspace.roles.store'), [
            'name' => 'Stagiair',
            'is_external' => false,
            'abilities' => [WorkspaceAbility::SendTransfers->value],
        ])
        ->assertSessionHasErrors('abilities');

    expect($workspace->roles()->where('name', 'Stagiair')->exists())->toBeFalse();
});

it('will not touch a role standing above the author', function () {
    [$admin, $workspace] = roleManager(SystemRole::Admin);

    $owner = $workspace->roles()->where('key', SystemRole::Owner->value)->first();

    actingAs($admin)
        ->patch(route('workspace.roles.update', $owner), [
            'name' => 'Iets anders',
            'is_external' => false,
            'abilities' => [],
        ])
        ->assertForbidden();

    expect($owner->fresh()->name)->not->toBe('Iets anders');
});

it('renames a role and rewrites what it may do', function () {
    [$owner, $workspace] = roleManager();

    $member = $workspace->roles()->where('key', SystemRole::Member->value)->first();

    actingAs($owner)
        ->patch(route('workspace.roles.update', $member), [
            'name' => 'Collega',
            'is_external' => false,
            'abilities' => [WorkspaceAbility::SeeMembers->value],
        ])
        ->assertRedirect();

    $member->refresh();

    expect($member->name)->toBe('Collega')
        ->and($member->allows(WorkspaceAbility::SeeMembers))->toBeTrue()
        ->and($member->allows(WorkspaceAbility::CreateChannels))->toBeFalse()
        // The key does not move with the name: everything that has to
        // recognise a role across a rename reads that instead.
        ->and($member->key)->toBe(SystemRole::Member->value);
});

it('leaves outside-ness alone once somebody holds the role', function () {
    [$owner, $workspace] = roleManager();

    $role = $workspace->roles()->create([
        'key' => 'custom-test',
        'name' => 'Leverancier',
        'is_external' => true,
        'position' => 9,
        'abilities' => [],
    ]);

    $holder = User::factory()->create();
    $workspace->members()->attach($holder->id, [
        'role' => SystemRole::Guest->value,
        'workspace_role_id' => $role->id,
        'joined_at' => now(),
    ]);

    actingAs($owner)
        ->patch(route('workspace.roles.update', $role), [
            'name' => 'Leverancier',
            'is_external' => false,
            'abilities' => [],
        ])
        ->assertRedirect();

    /*
     * Silently ignored rather than refused: the rest of the save is perfectly
     * good. What may not happen is somebody being moved across the line that
     * decides which channels exist for them, from a screen about tickboxes.
     */
    expect($role->fresh()->is_external)->toBeTrue();
});

it('refuses to delete a role somebody still holds', function () {
    [$owner, $workspace] = roleManager();

    $role = $workspace->roles()->create([
        'key' => 'custom-held',
        'name' => 'Vrijwilliger',
        'position' => 9,
        'abilities' => [],
    ]);

    $holder = User::factory()->create();
    $workspace->members()->attach($holder->id, [
        'role' => SystemRole::Member->value,
        'workspace_role_id' => $role->id,
        'joined_at' => now(),
    ]);

    actingAs($owner)
        ->delete(route('workspace.roles.destroy', $role))
        ->assertSessionHasErrors('role');

    expect(Role::whereKey($role->id)->exists())->toBeTrue();
});

it('never deletes one of the four a workspace starts with', function () {
    [$owner, $workspace] = roleManager();

    $member = $workspace->roles()->where('key', SystemRole::Member->value)->first();

    actingAs($owner)
        ->delete(route('workspace.roles.destroy', $member))
        ->assertStatus(422);

    expect(Role::whereKey($member->id)->exists())->toBeTrue();
});

it('deletes a role nobody holds', function () {
    [$owner, $workspace] = roleManager();

    $role = $workspace->roles()->create([
        'key' => 'custom-unused',
        'name' => 'Ongebruikt',
        'position' => 9,
        'abilities' => [],
    ]);

    actingAs($owner)
        ->delete(route('workspace.roles.destroy', $role))
        ->assertRedirect();

    expect(Role::whereKey($role->id)->exists())->toBeFalse();
});

it('keeps one workspace out of another his roles', function () {
    [$owner] = roleManager();
    [, $elsewhere] = roleManager();

    $theirs = $elsewhere->roles()->where('key', SystemRole::Member->value)->first();

    actingAs($owner)
        ->patch(route('workspace.roles.update', $theirs), [
            'name' => 'Overgenomen',
            'is_external' => false,
            'abilities' => [],
        ])
        ->assertNotFound();
});
