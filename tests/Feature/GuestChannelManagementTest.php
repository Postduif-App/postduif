<?php

use App\Enums\ChannelType;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * An owner who administers the workspace, and a guest to administer.
 *
 * @return array{0: User, 1: User, 2: Workspace}
 */
function workspaceWithOwnerAndGuest(): array
{
    $owner = User::factory()->create();
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);
    $workspace->update(['owner_id' => $owner->id]);

    $guest = User::factory()->create();
    $workspace->members()->attach($guest->id, [
        'role' => WorkspaceRole::Guest->value,
        'joined_at' => now(),
    ]);

    return [$owner, $guest, $workspace];
}

function channelIn(Workspace $workspace, string $name, ChannelType $type = ChannelType::Public): Channel
{
    return Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => $type,
        'name' => $name,
        'slug' => $name,
    ]);
}

it('lists a guest with the channels they are in', function () {
    [$owner, $guest, $workspace] = workspaceWithOwnerAndGuest();

    $invited = channelIn($workspace, 'klantproject', ChannelType::Private);
    $other = channelIn($workspace, 'algemeen');

    $invited->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members.1.role', 'guest')
            ->where('members.1.channelIds', [$invited->id])
            ->where('members.1.canManageChannels', true)
            // The owner's own row has no channel list: for a full member the
            // answer is the whole workspace.
            ->where('members.0.channelIds', null)
            ->where('members.0.canManageChannels', false)
            ->where('channelOptions', fn ($options) => collect($options)->pluck('id')->sort()->values()->all()
                === collect([$invited->id, $other->id])->sort()->values()->all()));
});

it('adds a guest to several channels at once', function () {
    [$owner, $guest, $workspace] = workspaceWithOwnerAndGuest();

    $first = channelIn($workspace, 'klantproject', ChannelType::Private);
    $second = channelIn($workspace, 'oplevering');

    actingAs($owner)
        ->put(route('workspace.members.channels.update', $guest), [
            'channel_ids' => [$first->id, $second->id],
        ])
        ->assertRedirect();

    expect($guest->channels()->pluck('channels.id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('removes a guest from the channels left out of the list', function () {
    [$owner, $guest, $workspace] = workspaceWithOwnerAndGuest();

    $stays = channelIn($workspace, 'klantproject', ChannelType::Private);
    $goes = channelIn($workspace, 'oplevering');

    $stays->members()->attach($guest->id, ['joined_at' => now()]);
    $goes->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($owner)
        ->put(route('workspace.members.channels.update', $guest), [
            'channel_ids' => [$stays->id],
        ])
        ->assertRedirect();

    expect($guest->channels()->pluck('channels.id')->all())->toBe([$stays->id]);
});

it('takes a guest out of everything when nothing is ticked', function () {
    [$owner, $guest, $workspace] = workspaceWithOwnerAndGuest();

    $channel = channelIn($workspace, 'klantproject', ChannelType::Private);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    actingAs($owner)
        ->put(route('workspace.members.channels.update', $guest))
        ->assertRedirect();

    expect($guest->channels()->count())->toBe(0);
});

it('ignores channels from another workspace', function () {
    [$owner, $guest] = workspaceWithOwnerAndGuest();

    $elsewhere = channelIn(Workspace::factory()->create(), 'ergens-anders');

    actingAs($owner)
        ->put(route('workspace.members.channels.update', $guest), [
            'channel_ids' => [$elsewhere->id],
        ])
        ->assertRedirect();

    expect($guest->channels()->count())->toBe(0);
});

it('leaves a direct message alone when syncing a guest', function () {
    [$owner, $guest, $workspace] = workspaceWithOwnerAndGuest();

    $dm = channelIn($workspace, 'dm', ChannelType::Direct);
    $dm->members()->attach([$owner->id, $guest->id], ['joined_at' => now()]);

    actingAs($owner)
        ->put(route('workspace.members.channels.update', $guest), ['channel_ids' => []])
        ->assertRedirect();

    expect($dm->members()->whereKey($guest->id)->exists())->toBeTrue();
});

it('refuses managing the channels of somebody who is not a guest', function () {
    [$owner, , $workspace] = workspaceWithOwnerAndGuest();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $channel = channelIn($workspace, 'algemeen');

    actingAs($owner)
        ->put(route('workspace.members.channels.update', $member), [
            'channel_ids' => [$channel->id],
        ])
        ->assertForbidden();

    expect($member->channels()->count())->toBe(0);
});

it('refuses an ordinary member managing a guest', function () {
    [, $guest, $workspace] = workspaceWithOwnerAndGuest();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($member)
        ->put(route('workspace.members.channels.update', $guest), ['channel_ids' => []])
        ->assertForbidden();
});

it('drops public channel access when a member is demoted to guest', function () {
    [$owner, , $workspace] = workspaceWithOwnerAndGuest();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $open = channelIn($workspace, 'algemeen');
    $closed = channelIn($workspace, 'directie', ChannelType::Private);
    $dm = channelIn($workspace, 'dm', ChannelType::Direct);

    foreach ([$open, $closed, $dm] as $channel) {
        $channel->members()->attach($member->id, ['joined_at' => now()]);
    }

    actingAs($owner)
        ->patch(route('workspace.members.update', $member), ['role' => 'guest'])
        ->assertRedirect();

    // The public channel they walked into themselves is gone; the private one
    // somebody put them in and the conversation they are having both stay.
    expect($open->members()->whereKey($member->id)->exists())->toBeFalse()
        ->and($closed->members()->whereKey($member->id)->exists())->toBeTrue()
        ->and($dm->members()->whereKey($member->id)->exists())->toBeTrue()
        ->and($workspace->channels()->visibleTo($member->fresh())->pluck('id')->sort()->values()->all())
        ->toBe(collect([$closed->id, $dm->id])->sort()->values()->all());
});

it('keeps a demoted member in the public channel they created themselves', function () {
    [$owner, , $workspace] = workspaceWithOwnerAndGuest();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $theirs = channelIn($workspace, 'hun-kanaal');
    $theirs->update(['created_by' => $member->id]);
    $theirs->members()->attach($member->id, ['joined_at' => now()]);

    actingAs($owner)
        ->patch(route('workspace.members.update', $member), ['role' => 'guest'])
        ->assertRedirect();

    expect($theirs->members()->whereKey($member->id)->exists())->toBeTrue();
});

it('leaves channel membership alone when promoting instead of demoting', function () {
    [$owner, , $workspace] = workspaceWithOwnerAndGuest();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => WorkspaceRole::Member->value,
        'joined_at' => now(),
    ]);

    $open = channelIn($workspace, 'algemeen');
    $open->members()->attach($member->id, ['joined_at' => now()]);

    actingAs($owner)
        ->patch(route('workspace.members.update', $member), ['role' => 'admin'])
        ->assertRedirect();

    expect($open->members()->whereKey($member->id)->exists())->toBeTrue();
});
