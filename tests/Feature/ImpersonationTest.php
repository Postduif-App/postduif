<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;

/**
 * A workspace with an owner who may step into people, an administrator who may
 * not, and an ordinary member to step into.
 *
 * The owner holds the right by seed; the administrator is given ManageMembers
 * and nothing else, which is the split this feature is built on — arranging who
 * belongs here does not come with reading their messages.
 *
 * @return array{0: User, 1: User, 2: User, 3: Workspace}
 */
function workspaceForImpersonation(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    joinWorkspace($workspace, $admin, SystemRole::Admin);
    joinWorkspace($workspace, $member, SystemRole::Member);

    return [$owner, $admin, $member, $workspace];
}

it('signs the owner in as a member and back out again', function () {
    [$owner, , $member] = workspaceForImpersonation();

    actingAs($owner)
        ->post(route('workspace.members.impersonate', $member))
        ->assertRedirect(route('chat.home'));

    assertAuthenticatedAs($member);

    delete(route('impersonation.destroy'))
        ->assertRedirect(route('workspace.members.index'));

    assertAuthenticatedAs($owner);
});

it('tells the impersonated session who is really sitting there', function () {
    [$owner, , $member] = workspaceForImpersonation();

    actingAs($owner)->post(route('workspace.members.impersonate', $member));

    $this->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.id', $member->id)
            ->where('auth.impersonator.name', $owner->name));
});

/**
 * The whole point of the right being its own: an administrator runs the
 * ledenlijst and still may not read a colleague's messages.
 */
it('refuses an administrator who only manages members', function () {
    [, $admin, $member] = workspaceForImpersonation();

    actingAs($admin)
        ->post(route('workspace.members.impersonate', $member))
        ->assertForbidden();

    assertAuthenticatedAs($admin);
});

it('lets an administrator in once the workspace hands them the right', function () {
    [, $admin, $member, $workspace] = workspaceForImpersonation();

    setAbility($workspace, WorkspaceAbility::ImpersonateMembers, true, SystemRole::Admin);

    actingAs($admin)
        ->post(route('workspace.members.impersonate', $member))
        ->assertRedirect();

    assertAuthenticatedAs($member);
});

/** Reaching up would make the right a way of becoming your own superior. */
it('never lets somebody step into a role above their own', function () {
    [$owner, $admin, , $workspace] = workspaceForImpersonation();

    setAbility($workspace, WorkspaceAbility::ImpersonateMembers, true, SystemRole::Admin);

    actingAs($admin)
        ->post(route('workspace.members.impersonate', $owner))
        ->assertForbidden();

    assertAuthenticatedAs($admin);
});

it('refuses somebody from another workspace', function () {
    [$owner] = workspaceForImpersonation();

    $stranger = User::factory()->create();
    workspaceWithMember($stranger, SystemRole::Member);

    actingAs($owner)
        ->post(route('workspace.members.impersonate', $stranger))
        ->assertForbidden();

    assertAuthenticatedAs($owner);
});

it('refuses a suspended member', function () {
    [$owner, , $member] = workspaceForImpersonation();

    $member->forceFill(['suspended_at' => now()])->save();

    actingAs($owner)
        ->post(route('workspace.members.impersonate', $member))
        ->assertForbidden();
});

/**
 * A platform moderator's account opens the admin panel over every workspace on
 * the installation, which is not a door a workspace hands out to itself.
 */
it('refuses a platform moderator', function () {
    [$owner, , $member] = workspaceForImpersonation();

    $member->forceFill(['admin_at' => now()])->save();

    actingAs($owner)
        ->post(route('workspace.members.impersonate', $member))
        ->assertForbidden();

    assertAuthenticatedAs($owner);
});

it('refuses to impersonate yourself', function () {
    [$owner] = workspaceForImpersonation();

    actingAs($owner)
        ->post(route('workspace.members.impersonate', $owner))
        ->assertForbidden();
});

/** A stack of impersonations is a stack that can be left half-unwound. */
it('refuses to nest one impersonation inside another', function () {
    [$owner, $admin, $member, $workspace] = workspaceForImpersonation();

    setAbility($workspace, WorkspaceAbility::ImpersonateMembers, true, SystemRole::Admin);

    actingAs($owner)->post(route('workspace.members.impersonate', $admin));

    post(route('workspace.members.impersonate', $member))->assertForbidden();

    assertAuthenticatedAs($admin);
});

it('leaves the identity screens shut while impersonating', function () {
    [$owner, , $member] = workspaceForImpersonation();

    actingAs($owner)->post(route('workspace.members.impersonate', $member));

    post(route('api-tokens.store'), ['name' => 'Sneaky'])->assertForbidden();
    delete(route('profile.destroy'), ['password' => 'password'])->assertForbidden();

    expect(ApiToken::query()->where('user_id', $member->id)->count())->toBe(0);
    assertAuthenticatedAs($member);
});

it('opens those screens again once it has stopped', function () {
    [$owner, , $member] = workspaceForImpersonation();

    actingAs($owner)->post(route('workspace.members.impersonate', $member));
    delete(route('impersonation.destroy'));

    post(route('api-tokens.store'), ['name' => 'Mine'])->assertRedirect();
});

it('does nothing when nobody is impersonating anybody', function () {
    [$owner] = workspaceForImpersonation();

    actingAs($owner)
        ->delete(route('impersonation.destroy'))
        ->assertRedirect();

    assertAuthenticatedAs($owner);
});

it('offers the action in the member list only to who may take it', function () {
    [$owner, $admin, $member, $workspace] = workspaceForImpersonation();

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)
                ->firstWhere('id', $member->id)['canImpersonate'] === true
                && collect($members)
                    ->firstWhere('id', $owner->id)['canImpersonate'] === false));

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members', fn ($members) => collect($members)
                ->every(fn (array $row): bool => $row['canImpersonate'] === false)));

    expect($workspace->allows($admin, WorkspaceAbility::ImpersonateMembers))->toBeFalse();
});
