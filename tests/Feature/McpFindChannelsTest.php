<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Features\AiAccess;
use App\Mcp\Servers\ChatServer;
use App\Mcp\Tools\FindChannelsTool;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A member with one channel they are in and one they are not.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function memberWithChannels(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    /*
     * These tests are about what an AI client can do where it has been let in.
     * Whether it is let in at all is a workspace's own decision, tested in
     * FeatureEnforcementTest — and switched off by default, which is why it has
     * to be said out loud here.
     */
    Feature::for($workspace)->activate(AiAccess::class);
    $channel = channelWithMember($workspace, $user);

    $channel->update(['name' => 'levering', 'slug' => 'levering']);

    return [$user, $workspace, $channel];
}

it('lists the channels this member can open', function () {
    [$user, , $channel] = memberWithChannels();

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class)
        ->assertOk()
        ->assertSee('levering')
        ->assertSee((string) $channel->id);
});

it('narrows down on a name', function () {
    [$user, $workspace] = memberWithChannels();

    $other = channelWithMember($workspace, $user);
    $other->update(['name' => 'inkoop', 'slug' => 'inkoop']);

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class, ['search' => 'lever'])
        ->assertOk()
        ->assertSee('levering')
        ->assertDontSee('inkoop');
});

/**
 * The same scope the sidebar uses. A second query that ought to agree drifts,
 * and here that would mean showing a private channel the browser hides.
 */
it('leaves out a private channel this member is not in', function () {
    [$user, $workspace] = memberWithChannels();

    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
        'name' => 'directie',
        'slug' => 'directie',
    ]);

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class)
        ->assertOk()
        ->assertDontSee('directie');
});

it('leaves out an archived channel', function () {
    [$user, , $channel] = memberWithChannels();

    $channel->forceFill(['archived_at' => now()])->save();

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class)
        ->assertSee('geen enkel kanaal');
});

it('says up front where this member may post', function () {
    [$user, , $channel] = memberWithChannels();

    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class)
        ->assertOk()
        // Compact JSON, so no space after the colon.
        ->assertSee('"canPost":false');
});

/** A guest is present for their own channels and nothing else. */
it('shows a guest only what they were invited to', function () {
    [, $workspace, $channel] = memberWithChannels();

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
        'name' => 'algemeen',
        'slug' => 'algemeen',
    ]);

    ChatServer::actingAs($guest)
        ->tool(FindChannelsTool::class)
        ->assertOk()
        ->assertSee('levering')
        ->assertDontSee('algemeen');
});
