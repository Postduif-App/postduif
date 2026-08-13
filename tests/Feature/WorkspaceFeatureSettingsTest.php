<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Documents;
use App\Features\Huddles;
use App\Features\Tickets;
use App\Features\WorkspaceFeature;
use App\Models\Role;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

it('shows the owner every part of the product and where it stands', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    Feature::for($workspace)->deactivate(Documents::class);

    actingAs($user)
        ->get(route('workspace.features.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace-features')
            ->has('features', count(WorkspaceFeature::ALL))
            ->where('features.5.key', Documents::key())
            ->where('features.5.enabled', false)
            // Huddles is one of the three a fresh workspace does not get.
            ->where('features.10.key', Huddles::key())
            ->where('features.10.onByDefault', false)
        );
});

it('refuses the screen to an administrator, who does not decide what the workspace is', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)->get(route('workspace.features.edit'))->assertForbidden();
});

it('refuses the screen to a plain member', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Member);

    actingAs($user)->get(route('workspace.features.edit'))->assertForbidden();
});

it('switches a part of the product off and leaves the rest alone', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->patch(route('workspace.features.update'), [
            'features' => [Documents::key()],
        ])
        ->assertRedirect();

    expect($workspace->hasFeature(Documents::class))->toBeTrue()
        ->and($workspace->hasFeature(Tickets::class))->toBeFalse();
});

it('switches one back on', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    Feature::for($workspace)->deactivate(Huddles::class);

    actingAs($user)
        ->patch(route('workspace.features.update'), [
            'features' => [Huddles::key()],
        ])
        ->assertRedirect();

    expect($workspace->hasFeature(Huddles::class))->toBeTrue();
});

it('takes an empty list as "everything off" rather than as a missing field', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    // What the form actually sends when nothing is ticked: one blank entry,
    // which ConvertEmptyStringsToNull has turned into a null by then.
    actingAs($user)
        ->patch(route('workspace.features.update'), ['features' => ['']])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($workspace->hasFeature(Documents::class))->toBeFalse();
});

it('refuses a name nothing answers to', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->patch(route('workspace.features.update'), [
            'features' => ['er-is-geen-onderdeel-dat-zo-heet'],
        ])
        ->assertSessionHasErrors('features.0');
});

it('refuses a change from an administrator', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->patch(route('workspace.features.update'), ['features' => []])
        ->assertForbidden();

    expect($workspace->hasFeature(Documents::class))->toBeTrue();
});

it('opens for an administrator once the owner hands the right over', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    $role = Role::query()->find(roleId($workspace, SystemRole::Admin));
    $role->update(['abilities' => [
        ...$role->abilities,
        WorkspaceAbility::ManageFeatures->value,
    ]]);

    actingAs($user)->get(route('workspace.features.edit'))->assertOk();
});

it('lists the screen in the navigation for the owner and nobody else', function () {
    $owner = User::factory()->create();
    workspaceWithMember($owner, SystemRole::Owner);

    actingAs($owner)
        ->get(route('workspace.features.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageFeatures', true));

    $admin = User::factory()->create();
    workspaceWithMember($admin, SystemRole::Admin);

    actingAs($admin)
        ->get(route('workspace.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageFeatures', false));
});
