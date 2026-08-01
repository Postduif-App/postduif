<?php

use App\Enums\ChannelType;
use App\Features\AiAccess;
use App\Mcp\Servers\ChatServer;
use App\Mcp\Tools\SearchMessagesTool;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

/**
 * A member with one message to find.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Message}
 */
function memberWithMessage(string $body = 'De levering komt dinsdag'): array
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

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => $body,
    ]);

    return [$user, $workspace, $channel, $message];
}

it('finds a message this member may read', function () {
    [$user] = memberWithMessage();

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => 'levering'])
        ->assertOk()
        ->assertSee('De levering komt dinsdag');
});

it('says so when nothing matches', function () {
    [$user] = memberWithMessage();

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => 'zeppelin'])
        ->assertOk()
        ->assertSee('Niets gevonden');
});

it('asks for something to search on', function () {
    [$user] = memberWithMessage();

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => '  '])
        ->assertHasErrors();
});

/** What the browser cannot reach, a client cannot reach either. */
it('does not find a message in a channel this member cannot see', function () {
    [$user, $workspace] = memberWithMessage();

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $private->id,
        'body' => 'Geheime overname',
    ]);

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => 'overname'])
        ->assertDontSee('Geheime overname');
});

it('narrows to one channel when asked', function () {
    [$user, $workspace, $channel] = memberWithMessage();

    $other = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $other->id,
        'user_id' => $user->id,
        'body' => 'De levering is al binnen',
    ]);

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, [
            'query' => 'levering',
            'channel_id' => $channel->id,
        ])
        ->assertSee('De levering komt dinsdag')
        ->assertDontSee('De levering is al binnen');
});

/**
 * The masking happens on render, but the index still holds what was typed — so
 * without stripping the term, searching for a blocked word finds every message
 * containing it. A tool that skipped this would be a way around the blocklist.
 */
it('strips a blocked word out of the search term', function () {
    [$user, $workspace] = memberWithMessage('Wat een gedoe met die klojo');

    $workspace->update(['blocked_words' => ['klojo']]);

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => 'klojo'])
        ->assertSee('Niets gevonden');
});

it('keeps the whole query for whoever runs the workspace', function () {
    [, $workspace] = memberWithMessage('Wat een gedoe met die klojo');

    $workspace->update(['blocked_words' => ['klojo']]);

    $owner = User::factory()->create();
    $workspace->members()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
    $workspace->channels->each(fn (Channel $channel) => $channel->members()
        ->syncWithoutDetaching([$owner->id => ['joined_at' => now()]]));

    // They decide what is on the list, and finding out whether it is being
    // ignored is the reason to have one. The hit itself stays masked.
    ChatServer::actingAs($owner)
        ->tool(SearchMessagesTool::class, ['query' => 'klojo'])
        ->assertOk()
        ->assertSee('Wat een gedoe met die')
        ->assertDontSee('klojo');
});

it('finds nothing in a workspace this member left', function () {
    [$user, $workspace] = memberWithMessage();

    $workspace->members()->detach($user->id);

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => 'levering'])
        ->assertSee('Niets gevonden');
});
