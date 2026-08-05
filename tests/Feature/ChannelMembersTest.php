<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A private channel with a member inside and a colleague who is in the
 * workspace but not (yet) in the channel.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function privateChannelWithOutsider(): array
{
    $insider = User::factory()->create();
    $colleague = User::factory()->create();

    $workspace = workspaceWithMember($insider);
    $workspace->members()->attach($colleague->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    $channel = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);
    $channel->members()->attach($insider->id, ['joined_at' => now()]);

    return [$insider, $colleague, $workspace, $channel];
}

it('adds a workspace member to a private channel', function () {
    [$insider, $colleague, $workspace, $channel] = privateChannelWithOutsider();

    actingAs($insider)
        ->post(route('chat.channels.members.store', [$workspace, $channel]), [
            'user_ids' => [$colleague->id],
        ])
        ->assertRedirect();

    expect($channel->members()->whereKey($colleague->id)->exists())->toBeTrue();
});

it('makes the channel visible to someone once they are added', function () {
    [$insider, $colleague, $workspace, $channel] = privateChannelWithOutsider();

    actingAs($colleague)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertForbidden();

    actingAs($insider)->post(route('chat.channels.members.store', [$workspace, $channel]), [
        'user_ids' => [$colleague->id],
    ]);

    actingAs($colleague)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk();
});

it('refuses to add anyone from outside the channel', function () {
    [, $colleague, $workspace, $channel] = privateChannelWithOutsider();

    // The colleague can see neither the channel nor its member list, so they
    // certainly cannot add themselves to it.
    actingAs($colleague)
        ->post(route('chat.channels.members.store', [$workspace, $channel]), [
            'user_ids' => [$colleague->id],
        ])
        ->assertForbidden();

    expect($channel->members()->whereKey($colleague->id)->exists())->toBeFalse();
});

/**
 * Ids come from a browser, so the action re-derives who is eligible from the
 * workspace instead of trusting the request.
 */
it('silently ignores a user from another workspace', function () {
    [$insider, , $workspace, $channel] = privateChannelWithOutsider();
    $stranger = User::factory()->create();
    workspaceWithMember($stranger);

    actingAs($insider)
        ->post(route('chat.channels.members.store', [$workspace, $channel]), [
            'user_ids' => [$stranger->id],
        ])
        ->assertRedirect();

    expect($channel->members()->whereKey($stranger->id)->exists())->toBeFalse();
});

it('does not add the same person twice', function () {
    [$insider, $colleague, $workspace, $channel] = privateChannelWithOutsider();

    $payload = ['user_ids' => [$colleague->id, $colleague->id]];

    actingAs($insider)->post(route('chat.channels.members.store', [$workspace, $channel]), $payload);
    actingAs($insider)->post(route('chat.channels.members.store', [$workspace, $channel]), $payload);

    expect($channel->members()->whereKey($colleague->id)->count())->toBe(1);
});

it('never allows adding someone to a direct message', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $third = User::factory()->create();

    $workspace = workspaceWithMember($user);
    foreach ([$partner, $third] as $member) {
        $workspace->members()->attach($member->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);
    }

    $dm = Channel::factory()->direct()->create(['workspace_id' => $workspace->id]);
    $dm->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    actingAs($user)
        ->post(route('chat.channels.members.store', [$workspace, $dm]), [
            'user_ids' => [$third->id],
        ])
        ->assertForbidden();

    expect($dm->members()->count())->toBe(2);
});

it('offers only workspace members who are not in the channel yet', function () {
    [$insider, $colleague, $workspace, $channel] = privateChannelWithOutsider();

    actingAs($insider)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]))
        ->assertOk()
        ->assertJsonCount(1, 'candidates')
        ->assertJsonPath('candidates.0.id', $colleague->id);

    actingAs($insider)->post(route('chat.channels.members.store', [$workspace, $channel]), [
        'user_ids' => [$colleague->id],
    ]);

    actingAs($insider)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]))
        ->assertJsonCount(0, 'candidates');
});

it('filters candidates by name or handle', function () {
    [$insider, $colleague, $workspace, $channel] = privateChannelWithOutsider();
    $colleague->forceFill(['name' => 'Amara Okafor', 'username' => 'amara'])->save();

    actingAs($insider)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]).'?q=okaf')
        ->assertJsonCount(1, 'candidates');

    actingAs($insider)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]).'?q=amar')
        ->assertJsonCount(1, 'candidates');

    actingAs($insider)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]).'?q=zzz')
        ->assertJsonCount(0, 'candidates');
});

it('finds a handle whether or not the @ is typed along with it', function () {
    [$insider, $colleague, $workspace, $channel] = privateChannelWithOutsider();
    $colleague->forceFill(['name' => 'Amara Okafor', 'username' => 'amara'])->save();

    foreach (['amara', '@amara'] as $terms) {
        actingAs($insider)
            ->getJson(route('chat.channels.members.index', [$workspace, $channel]).'?q='.urlencode($terms))
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.id', $colleague->id);
    }
});

it('never leaks the candidate list to an outsider', function () {
    [, $colleague, $workspace, $channel] = privateChannelWithOutsider();

    actingAs($colleague)
        ->getJson(route('chat.channels.members.index', [$workspace, $channel]))
        ->assertForbidden();
});

it('lets a member leave a channel', function () {
    [$insider, , $workspace, $channel] = privateChannelWithOutsider();
    channelWithMember($workspace, $insider);

    actingAs($insider)
        ->delete(route('chat.channels.members.destroy', [$workspace, $channel]))
        ->assertRedirect(route('chat.index', $workspace, absolute: false));

    expect($channel->members()->whereKey($insider->id)->exists())->toBeFalse();
});

it('refuses to let anyone leave a direct message', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->members()->attach($partner->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    $dm = Channel::factory()->direct()->create(['workspace_id' => $workspace->id]);
    $dm->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    actingAs($user)
        ->delete(route('chat.channels.members.destroy', [$workspace, $dm]))
        ->assertForbidden();

    expect($dm->members()->count())->toBe(2);
});

/**
 * A channel whose creator is known, plus a second member.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function channelWithOwnerAndMember(): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $workspace = workspaceWithMember($owner);
    $workspace->members()->attach($member->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $owner->id,
    ]);
    $channel->members()->attach([$owner->id, $member->id], ['joined_at' => now()]);

    return [$owner, $member, $workspace, $channel];
}

it('removes another member from a channel', function () {
    [$owner, $member, $workspace, $channel] = channelWithOwnerAndMember();

    actingAs($owner)
        ->delete(route('chat.channels.members.remove', [$workspace, $channel, $member]))
        ->assertRedirect();

    expect($channel->members()->whereKey($member->id)->exists())->toBeFalse();
});

it('lets any member remove any other member', function () {
    [$owner, $member, $workspace, $channel] = channelWithOwnerAndMember();
    $third = User::factory()->create();
    $workspace->members()->attach($third->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);
    $channel->members()->attach($third->id, ['joined_at' => now()]);

    actingAs($member)
        ->delete(route('chat.channels.members.remove', [$workspace, $channel, $third]))
        ->assertRedirect();

    expect($channel->members()->whereKey($third->id)->exists())->toBeFalse()
        ->and($owner->exists)->toBeTrue();
});

/**
 * The creator is the only member with a claim to the channel. Letting them go
 * would leave a private channel with nobody responsible for who gets in.
 */
it('refuses to remove the channel owner', function () {
    [$owner, $member, $workspace, $channel] = channelWithOwnerAndMember();

    actingAs($member)
        ->delete(route('chat.channels.members.remove', [$workspace, $channel, $owner]))
        ->assertForbidden();

    expect($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});

it('refuses to let the owner leave their own channel', function () {
    [$owner, , $workspace, $channel] = channelWithOwnerAndMember();

    actingAs($owner)
        ->delete(route('chat.channels.members.destroy', [$workspace, $channel]))
        ->assertForbidden();

    expect($channel->members()->whereKey($owner->id)->exists())->toBeTrue();
});

it('still lets a non owner leave', function () {
    [, $member, $workspace, $channel] = channelWithOwnerAndMember();
    channelWithMember($workspace, $member);

    actingAs($member)
        ->delete(route('chat.channels.members.destroy', [$workspace, $channel]))
        ->assertRedirect();

    expect($channel->members()->whereKey($member->id)->exists())->toBeFalse();
});

it('tells the page that the owner cannot leave', function () {
    [$owner, $member, $workspace, $channel] = channelWithOwnerAndMember();

    actingAs($owner)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.canLeave', false)
            ->where('channel.createdBy', $owner->id)
        );

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.canLeave', true));
});

it('refuses to remove someone from outside the channel', function () {
    [$owner, , $workspace, $channel] = channelWithOwnerAndMember();
    $stranger = User::factory()->create();
    $workspace->members()->attach($stranger->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    actingAs($owner)
        ->delete(route('chat.channels.members.remove', [$workspace, $channel, $stranger]))
        ->assertForbidden();
});

it('refuses removal by someone who is not in the channel', function () {
    [, $member, $workspace, $channel] = channelWithOwnerAndMember();
    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    actingAs($outsider)
        ->delete(route('chat.channels.members.remove', [$workspace, $channel, $member]))
        ->assertForbidden();

    expect($channel->members()->whereKey($member->id)->exists())->toBeTrue();
});

it('never removes anyone from a direct message', function () {
    $user = User::factory()->create();
    $partner = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->members()->attach($partner->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    $dm = Channel::factory()->direct()->create(['workspace_id' => $workspace->id]);
    $dm->members()->attach([$user->id, $partner->id], ['joined_at' => now()]);

    actingAs($user)
        ->delete(route('chat.channels.members.remove', [$workspace, $dm, $partner]))
        ->assertForbidden();

    expect($dm->members()->count())->toBe(2);
});
