<?php

use App\Enums\SystemRole;
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
    $workspace->members()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
    $workspace->forceFill(['owner_id' => $owner->id])->save();

    return [$owner, $admin, $member, $workspace];
}

function roleOf(Workspace $workspace, User $user): ?SystemRole
{
    return $workspace->roleFor($user);
}

it('promotes a member to admin', function () {
    [$owner, , $member, $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.update', $member), ['role' => 'admin'])
        ->assertRedirect();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Admin);
});

it('lets an admin manage ordinary members', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $member), ['role' => 'admin'])
        ->assertRedirect();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Admin);
});

/**
 * An admin who could demote the owner would effectively outrank them, which is
 * not what the roles say.
 */
it('never lets an admin touch the owner', function () {
    [$owner, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $owner), ['role' => 'member'])
        ->assertForbidden();

    expect(roleOf($workspace, $owner))->toBe(SystemRole::Owner);
});

it('never lets an admin hand out ownership', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $member), ['role' => 'owner'])
        ->assertForbidden();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Member);
});

/**
 * The point of allowing self-edit: a sole owner has to be able to hand the
 * workspace over. Appoint a successor, then step down.
 */
it('lets an owner hand the workspace over', function () {
    [$owner, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.update', $admin), ['role' => 'owner'])
        ->assertRedirect();

    actingAs($owner)
        ->patch(route('workspace.members.update', $owner), ['role' => 'admin'])
        ->assertRedirect();

    expect(roleOf($workspace, $admin))->toBe(SystemRole::Owner)
        ->and(roleOf($workspace, $owner))->toBe(SystemRole::Admin);
});

/**
 * A workspace without an owner has nobody who can hand out roles, and no way
 * back. The sole owner is the only person who could cause it, so this guard is
 * exactly what stops them.
 */
it('refuses to step down the only owner', function () {
    [$owner, , , $workspace] = workspaceWithThreeRoles();

    actingAs($owner)
        ->patch(route('workspace.members.update', $owner), ['role' => 'admin'])
        ->assertSessionHasErrors('role');

    expect(roleOf($workspace, $owner))->toBe(SystemRole::Owner);
});

it('never lets an admin promote themselves to owner', function () {
    [, $admin, , $workspace] = workspaceWithThreeRoles();

    actingAs($admin)
        ->patch(route('workspace.members.update', $admin), ['role' => 'owner'])
        ->assertForbidden();

    expect(roleOf($workspace, $admin))->toBe(SystemRole::Admin);
});

it('refuses a role change from a plain member', function () {
    [, $admin, $member, $workspace] = workspaceWithThreeRoles();

    actingAs($member)
        ->patch(route('workspace.members.update', $admin), ['role' => 'member'])
        ->assertForbidden();

    expect(roleOf($workspace, $admin))->toBe(SystemRole::Admin);
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
        ->patch(route('workspace.members.update', $member), ['role' => 'guest'])
        ->assertRedirect();

    expect(roleOf($workspace, $member))->toBe(SystemRole::Guest);
});

it('refuses the settings screen to a guest', function () {
    $guest = User::factory()->create();
    workspaceWithMember($guest, SystemRole::Guest);

    actingAs($guest)->get(route('workspace.members.index'))->assertForbidden();
});

it('offers an admin every role except owner', function () {
    [, $admin] = workspaceWithThreeRoles();

    actingAs($admin)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->has('roleOptions', 3)
            ->where('roleOptions.0.value', 'admin')
            ->where('roleOptions.1.value', 'member')
            ->where('roleOptions.2.value', 'guest')
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
