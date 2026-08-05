<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A channel with its creator and one ordinary member in it.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function settingsFixture(): array
{
    $creator = User::factory()->create();
    $workspace = workspaceWithMember($creator);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $creator->id,
    ]);
    $channel->members()->attach($creator->id, ['joined_at' => now()]);

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    return [$creator, $member, $workspace, $channel];
}

function saveSettings(User $user, Workspace $workspace, Channel $channel, string $policy)
{
    return actingAs($user)->patch(
        route('chat.channels.update', [$workspace, $channel]),
        ['posting_policy' => $policy]
    );
}

it('lets the channel creator close the channel for posting', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    saveSettings($creator, $workspace, $channel, 'admins')->assertRedirect();

    expect($channel->fresh()->posting_policy)->toBe(ChannelPostingPolicy::Admins);
});

it('lets whoever runs the workspace change it too', function () {
    [, $member, $workspace, $channel] = settingsFixture();

    $workspace->members()->updateExistingPivot($member->id, [
        'role' => SystemRole::Admin->value,
    ]);

    saveSettings($member, $workspace, $channel, 'admins')->assertRedirect();

    expect($channel->fresh()->posting_policy)->toBe(ChannelPostingPolicy::Admins);
});

it('refuses an ordinary member', function () {
    [, $member, $workspace, $channel] = settingsFixture();

    saveSettings($member, $workspace, $channel, 'admins')->assertForbidden();

    expect($channel->fresh()->posting_policy)->toBe(ChannelPostingPolicy::Everyone);
});

it('refuses a value that is not one of the options', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    saveSettings($creator, $workspace, $channel, 'alleen-de-baas')
        ->assertSessionHasErrors('posting_policy');

    expect($channel->fresh()->posting_policy)->toBe(ChannelPostingPolicy::Everyone);
});

it('has nothing to configure on a direct message', function () {
    [$creator, $member, $workspace] = settingsFixture();

    $dm = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Direct,
        'name' => null,
        'slug' => null,
        'created_by' => $creator->id,
    ]);
    $dm->members()->attach([$creator->id, $member->id], ['joined_at' => now()]);

    saveSettings($creator, $workspace, $dm, 'admins')->assertForbidden();
});

it('refuses a channel from another workspace', function () {
    [$creator, , , $channel] = settingsFixture();

    $elsewhere = workspaceWithMember($creator);

    saveSettings($creator, $elsewhere, $channel, 'admins')->assertNotFound();
});

it('tells the page who may open the settings', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.canManageSettings', true));

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.canManageSettings', false));
});
