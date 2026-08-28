<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: User, 1: User, 2: User, 3: Workspace}
 */
function workspaceWithThreeRoles(): array
{
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();

    $workspace = workspaceWithMember($owner, SystemRole::Owner);
    joinWorkspace($workspace, $admin, SystemRole::Admin);
    joinWorkspace($workspace, $member, SystemRole::Member);
    $workspace->forceFill(['owner_id' => $owner->id])->save();

    return [$owner, $admin, $member, $workspace];
}

/** The key of the role somebody holds, which is what these tests compare. */
function roleOf(Workspace $workspace, User $user): ?string
{
    return $workspace->roleFor($user)?->key;
}

it('promotes a member to admin', function () {
    [$owner, , $member, $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.update', $member), ['role' => roleId($workspace, SystemRole::Admin)])
        ->assertRedirect();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Admin->value);
});

it('lets an admin manage ordinary members', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $member), ['role' => roleId($workspace, SystemRole::Admin)])
        ->assertRedirect();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Admin->value);
});

/**
 * An admin who could demote the owner would effectively outrank them, which is
 * not what the roles say.
 */
it('never lets an admin touch the owner', function () {
    [$owner, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $owner), ['role' => roleId($workspace, SystemRole::Member)])
        ->assertForbidden();

    expect(roleOf($workspace, $owner))->toBe(SystemRole::Owner->value);
});

it('never lets an admin hand out ownership', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $member), ['role' => roleId($workspace, SystemRole::Owner)])
        ->assertForbidden();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Member->value);
});

/**
 * The point of allowing self-edit: a sole owner has to be able to hand the
 * workspace over. Appoint a successor, then step down.
 */
it('lets an owner hand the workspace over', function () {
    [$owner, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.update', $admin), ['role' => roleId($workspace, SystemRole::Owner)])
        ->assertRedirect();

    actingAs($owner)
        ->patch(route('workspace.members.update', $owner), ['role' => roleId($workspace, SystemRole::Admin)])
        ->assertRedirect();

    expect(roleOf($workspace, $admin))->toBe(SystemRole::Owner->value)
        ->and(roleOf($workspace, $owner))->toBe(SystemRole::Admin->value);
});

/**
 * A workspace without an owner has nobody who can hand out roles, and no way
 * back. The sole owner is the only person who could cause it, so this guard is
 * exactly what stops them.
 */
/**
 * What has to survive is not a role by that name but somebody who can run the
 * place. A workspace may have three roles that manage it and none of them
 * called "eigenaar", so the guard asks about the right rather than the name.
 */
it('lets the only owner step down to another role that can still manage', function () {
    [$owner, , , $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.update', $owner), ['role' => roleId($workspace, SystemRole::Admin)])
        ->assertRedirect();

    expect(roleOf($workspace, $owner))->toBe(SystemRole::Admin->value);
});

it('refuses to leave a workspace with nobody who can manage it', function () {
    [$owner, $admin, , $workspace] = workspaceWithThreeRoles();

    // The administrator first, so the owner is the last one left who can.
    actingAs($owner)
        ->patch(route('workspace.members.update', $admin), ['role' => roleId($workspace, SystemRole::Member)])
        ->assertRedirect();

    actingAs($owner)
        ->patch(route('workspace.members.update', $owner), ['role' => roleId($workspace, SystemRole::Member)])
        ->assertSessionHasErrors('role');

    expect(roleOf($workspace, $owner))->toBe(SystemRole::Owner->value);
});

it('never lets an admin promote themselves to owner', function () {
    [, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $admin), ['role' => roleId($workspace, SystemRole::Owner)])
        ->assertForbidden();

    expect(roleOf($workspace, $admin))->toBe(SystemRole::Admin->value);
});

it('refuses a role change from a plain member', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($member)
        ->patch(route('workspace.members.update', $admin), ['role' => roleId($workspace, SystemRole::Member)])
        ->assertForbidden();

    expect(roleOf($workspace, $admin))->toBe(SystemRole::Admin->value);
});

it('removes a member from the workspace', function () {
    [$owner, , $member, $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->delete(route('workspace.members.destroy', $member))
        ->assertRedirect();

    expect($workspace->hasMember($member))->toBeFalse();
});

it('takes their channel memberships with them', function () {
    [$owner, , $member, $workspace] = workspaceWithThreeRoles();
    $channel = channelWithMember($workspace, $member);

    actingAs($owner)->delete(route('workspace.members.destroy', $member));

    expect($channel->members()->whereKey($member->id)->exists())->toBeFalse();
});

/**
 * A channel's creator cannot be removed from it and cannot leave, so a creator
 * who is no longer in the workspace would freeze that channel's membership.
 */
it('hands their channels to the workspace owner', function () {
    [$owner, , $member, $workspace] = workspaceWithThreeRoles();
    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $member->id,
    ]);

    actingAs($owner)->delete(route('workspace.members.destroy', $member));

    expect($channel->fresh()->created_by)->toBe($owner->id);
});

it('leaves their messages readable', function () {
    [$owner, , $member, $workspace] = workspaceWithThreeRoles();
    $channel = channelWithMember($workspace, $member);
    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $member->id,
        'body' => 'Blijft staan',
    ]);

    actingAs($owner)->delete(route('workspace.members.destroy', $member));

    expect($message->fresh()->body)->toBe('Blijft staan');
});

it('never removes the owner', function () {
    [$owner, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->delete(route('workspace.members.destroy', $owner))
        ->assertForbidden();

    expect($workspace->hasMember($owner))->toBeTrue();
});

/**
 * Walking out of a workspace is a different thing from being shown the door,
 * and it does not belong on the page where you administer other people.
 */
it('never lets anyone remove themselves here', function () {
    [, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->delete(route('workspace.members.destroy', $admin))
        ->assertForbidden();

    expect($workspace->hasMember($admin))->toBeTrue();
});

it('refuses a removal by a plain member', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($member)
        ->delete(route('workspace.members.destroy', $admin))
        ->assertForbidden();

    expect($workspace->hasMember($admin))->toBeTrue();
});

it('never touches somebody in another workspace', function () {
    [$owner] = workspaceWithThreeRoles();
    $stranger = User::factory()->create();
    $other = workspaceWithMember($stranger, SystemRole::Member);

    actingAs($owner)
        ->delete(route('workspace.members.destroy', $stranger))
        ->assertForbidden();

    expect($other->hasMember($stranger))->toBeTrue();
});

it('tells the page what each row may do', function () {
    [$owner, $admin, $member] = workspaceWithThreeRoles();

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(function ($page) use ($owner, $admin, $member) {
            $rows = collect($page->toArray()['props']['members'])->keyBy('id');

            expect($rows[$owner->id]['canChangeRole'])->toBeFalse()
                ->and($rows[$owner->id]['canRemove'])->toBeFalse()
                // An admin may edit their own role but not remove themselves.
                ->and($rows[$admin->id]['canChangeRole'])->toBeTrue()
                ->and($rows[$admin->id]['canRemove'])->toBeFalse()
                ->and($rows[$member->id]['canChangeRole'])->toBeTrue()
                ->and($rows[$member->id]['canRemove'])->toBeTrue();

            return $page;
        });
});

it('turns a member into a guest', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $member), ['role' => roleId($workspace, SystemRole::Guest)])
        ->assertRedirect();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Guest->value);
});

it('refuses the settings screen to a guest', function () {
    $guest = User::factory()->create();
    workspaceWithMember($guest, SystemRole::Guest);

    actingAs($guest)->get(route('workspace.members.index'))->assertForbidden();
});

it('offers an admin every role except the one standing above them', function () {
    [, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            /*
             * Three of the four. Owner is missing not because of its name but
             * because it stands above the administrator in this workspace's own
             * order — see Role::isUnder.
             */
            ->has('roleOptions', 3)
            ->where('roleOptions.0.value', roleId($workspace, SystemRole::Admin))
            ->where('roleOptions.1.value', roleId($workspace, SystemRole::Member))
            ->where('roleOptions.2.value', roleId($workspace, SystemRole::Guest))
        );
});

it('gives the member table what it draws each column from', function () {
    [$owner, $admin, $member, $workspace] = workspaceWithThreeRoles();

    $member->forceFill([
        'status_emoji' => '☕',
        'status_text' => 'Koffie halen',
    ])->save();

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->has('members', 3)
            // Standing order, which is what the table shows until somebody
            // sorts it: whoever runs the workspace is who you came looking for.
            ->where('members.0.id', $owner->id)
            ->where('members.1.id', $admin->id)
            ->where('members.2.id', $member->id)
            ->where('members.2.statusEmoji', '☕')
            ->where('members.2.statusText', 'Koffie halen')
            ->where('members.2.availability', 'available')
            ->has('members.0.joinedAt')
            ->has('members.0.username')
        );
});

it('says nothing about a status that was never set', function () {
    [$owner] = workspaceWithThreeRoles();

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members.0.statusText', null)
            ->where('members.0.statusEmoji', null)
        );
});

it('keeps the member table shut for an ordinary member', function () {
    [, , $member] = workspaceWithThreeRoles();

    actingAs($member)
        ->get(route('workspace.members.index'))
        ->assertForbidden();
});

it('offers a row its actions only to somebody who may take them', function () {
    [$owner, $admin] = workspaceWithThreeRoles();

    // The row menu is drawn from these flags alone, so what an admin may not do
    // to the owner is a menu that is simply not there — the same rule the
    // endpoints enforce, asked once.
    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members.0.id', $owner->id)
            ->where('members.0.canChangeRole', false)
            ->where('members.0.canRemove', false)
            ->where('members.2.canRemove', true)
        );
});

/**
 * Inviting somebody used to live only in the chat sidebar, which meant leaving
 * the page that lists everybody to add to it. The member list carries the same
 * dialog now, so it needs the three things that dialog reads: which workspace
 * to post to, whether to offer the button at all, and the roles on offer.
 */
it('gives the member list what it needs to invite somebody', function () {
    [, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('workspaceSlug', $workspace->slug)
            ->where('canInvite', true)
            /*
             * The same three the role dropdown offers: owner is missing because
             * it stands above an administrator, not because of its name.
             */
            ->has('invitableRoles', 3)
            ->where('invitableRoles.0.id', roleId($workspace, SystemRole::Admin))
            ->where('invitableRoles.2.id', roleId($workspace, SystemRole::Guest))
            ->where('invitableRoles.2.isExternal', true)
        );
});

/**
 * Managing the workspace and bringing people into it are separate rights. A
 * role that lost the second keeps this page — and loses the button, rather than
 * getting one that answers 403.
 */
it('offers no invite button to somebody who may not invite', function () {
    [, $admin, , $workspace] = workspaceWithThreeRoles();

    $role = $workspace->roles()->whereKey(roleId($workspace, SystemRole::Admin))->first();
    $role->forceFill([
        'abilities' => array_values(array_diff(
            $role->abilities,
            [WorkspaceAbility::InviteMembers->value],
        )),
    ])->save();

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page->where('canInvite', false));
});

/**
 * The point of splitting "leden beheren" off from managing the workspace: a
 * role can be trusted with who belongs here without being handed the settings.
 */
it('lets a role that only manages members do exactly that', function () {
    [, , $member, $workspace] = workspaceWithThreeRoles();

    $admin = $workspace->roles()->whereKey(roleId($workspace, SystemRole::Admin))->first();

    // The administrator's rights minus the one that reaches every other, which
    // leaves the members right standing on its own.
    $role = $workspace->roles()->create([
        'key' => 'officemanager',
        'name' => 'Officemanager',
        'position' => $admin->position,
        'abilities' => array_values(array_diff(
            $admin->abilities,
            [WorkspaceAbility::ManageWorkspace->value],
        )),
    ]);

    $officeManager = User::factory()->create();
    $workspace->members()->attach($officeManager->id, [
        'workspace_role_id' => $role->id,
        'joined_at' => now(),
    ]);

    expect($officeManager->can('manage', $workspace))->toBeFalse();

    actingAs($officeManager)->get(route('workspace.members.index'))->assertOk();

    actingAs($officeManager)
        ->delete(route('workspace.members.destroy', $member))
        ->assertRedirect();

    expect($workspace->fresh()->hasMember($member))->toBeFalse();
});

/**
 * And the other direction, which is what makes it a right rather than a label:
 * running the workspace no longer carries the ledenlijst by itself.
 */
it('refuses the ledenlijst to somebody whose role lost the members right', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    $role = $workspace->roles()->whereKey(roleId($workspace, SystemRole::Admin))->first();
    $role->forceFill([
        'abilities' => array_values(array_diff(
            $role->abilities,
            [WorkspaceAbility::ManageMembers->value],
        )),
    ])->save();

    actingAs($admin)->get(route('workspace.members.index'))->assertForbidden();

    actingAs($admin)
        ->delete(route('workspace.members.destroy', $member))
        ->assertForbidden();

    expect($workspace->fresh()->hasMember($member))->toBeTrue();
});

it('gives a member a different handle', function () {
    [$owner, , $member] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.handle.update', $member), ['username' => 'fenna.jansen'])
        ->assertRedirect();

    expect($member->fresh()->username)->toBe('fenna.jansen');
});

/**
 * Handles are stored and looked up in lowercase — see RecordMentions — so a
 * capital is something to fix rather than something to refuse.
 */
it('stores a typed handle in lowercase', function () {
    [$owner, , $member] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.handle.update', $member), ['username' => '  Fenna.Jansen '])
        ->assertRedirect();

    expect($member->fresh()->username)->toBe('fenna.jansen');
});

it('refuses a handle somebody else already has', function () {
    [$owner, $admin, $member] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.handle.update', $member), ['username' => $admin->username])
        ->assertSessionHasErrors('username');

    expect($member->fresh()->username)->not->toBe($admin->username);
});

it('refuses a handle that addresses a whole group', function () {
    [$owner, , $member] = workspaceWithThreeRoles();
    $before = $member->username;

    actingAs($owner)
        ->patch(route('workspace.members.handle.update', $member), ['username' => 'everyone'])
        ->assertSessionHasErrors('username');

    expect($member->fresh()->username)->toBe($before);
});

/**
 * The shape is not decoration: RecordMentions looks for exactly this after an
 * "@", so a handle outside it is one nobody can mention.
 */
it('refuses a handle nobody could mention', function (string $handle) {
    [$owner, , $member] = workspaceWithThreeRoles();
    $before = $member->username;

    actingAs($owner)
        ->patch(route('workspace.members.handle.update', $member), ['username' => $handle])
        ->assertSessionHasErrors('username');

    expect($member->fresh()->username)->toBe($before);
})->with([
    'een spatie' => 'fenna jansen',
    'leestekens' => 'fenna!',
    'begint met een punt' => '.fenna',
    'eindigt op een punt' => 'fenna.',
    'te lang' => 'fenna.jansen.van.den.berg.uit.eerbeek',
]);

it('refuses a handle change from a plain member', function () {
    [$owner, , $member] = workspaceWithThreeRoles();
    $before = $owner->username;

    actingAs($member)
        ->patch(route('workspace.members.handle.update', $owner), ['username' => 'iets.anders'])
        ->assertForbidden();

    expect($owner->fresh()->username)->toBe($before);
});

/** An admin may not reach the owner's row here either. */
it('never lets an admin rename the owner', function () {
    [$owner, $admin] = workspaceWithThreeRoles();
    $before = $owner->username;

    actingAs($admin)
        ->patch(route('workspace.members.handle.update', $owner), ['username' => 'iets.anders'])
        ->assertForbidden();

    expect($owner->fresh()->username)->toBe($before);
});

it('tells the page which rows may be renamed', function () {
    [, $admin] = workspaceWithThreeRoles();

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            // The owner sorts first and stands above an administrator.
            ->where('members.0.canChangeHandle', false)
            ->where('members.1.canChangeHandle', true));
});
