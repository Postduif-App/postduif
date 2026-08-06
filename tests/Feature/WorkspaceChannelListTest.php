<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The channel table in workspace settings. What it is for is the things the
 * sidebar leaves out: archived channels, and everything true about a channel
 * that is not worth a row's width in the chat.
 */
it('lists the workspace channels with what hangs off them', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'name' => 'algemeen',
        'topic' => 'Waar alles begint',
    ]);
    $channel->members()->attach($admin->id, ['joined_at' => now()]);
    Message::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $admin->id,
    ]);

    actingAs($admin)
        ->get(route('workspace.channels.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace-channels')
            ->where('channels.0.name', 'algemeen')
            ->where('channels.0.topic', 'Waar alles begint')
            ->where('channels.0.memberCount', 1)
            ->where('channels.0.messageCount', 3)
            ->where('channels.0.createdBy', $admin->name)
            ->where('channels.0.archivedAt', null));
});

it('shows the archived channels the sidebar hides', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    // archived_at is deliberately not fillable — see the Channel model.
    $archived = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'name' => 'vorig-jaar',
    ]);
    // A member as well as the creator: ChannelPolicy asks the creator to still
    // be in the channel, and only falls back to the workspace ability for
    // somebody else's.
    $archived->members()->attach($admin->id, ['joined_at' => now()]);
    $archived->forceFill(['archived_at' => now()])->save();

    actingAs($admin)
        ->get(route('workspace.channels.index'))
        ->assertInertia(fn ($page) => $page
            ->has('channels', 1)
            ->where('channels.0.name', 'vorig-jaar')
            ->whereNot('channels.0.archivedAt', null)
            // An archived channel may still be brought back, which is the one
            // thing that cannot be reached from the chat once it is gone.
            ->where('channels.0.canArchive', true));
});

it('leaves direct messages out', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'type' => 'dm',
        'name' => null,
    ]);

    // A conversation between two people is not something the workspace made,
    // and listing them here would turn this into a directory of who talks to
    // whom.
    actingAs($admin)
        ->get(route('workspace.channels.index'))
        ->assertInertia(fn ($page) => $page->has('channels', 0));
});

it('refuses somebody who does not manage the workspace', function () {
    $member = User::factory()->create();
    workspaceWithMember($member, SystemRole::Member);

    actingAs($member)
        ->get(route('workspace.channels.index'))
        ->assertForbidden();
});

it('says per channel whether this member may archive it', function () {
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $admin->id,
        'name' => 'algemeen',
    ]);
    $channel->members()->attach($admin->id, ['joined_at' => now()]);

    actingAs($admin)
        ->get(route('workspace.channels.index'))
        ->assertInertia(fn ($page) => $page
            ->where('channels.0.canArchive', true)
            ->where('channels.0.canOpen', true));
});
