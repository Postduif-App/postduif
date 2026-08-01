<?php

use App\Features\AiAccess;
use App\Features\Webhooks;
use App\Mcp\Servers\ChatServer;
use App\Mcp\Tools\FindChannelsTool;
use App\Mcp\Tools\SearchMessagesTool;
use App\Mcp\Tools\SendMessageTool;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use Laravel\Pennant\Feature;

/**
 * A member with a channel and a message in it, in a workspace that has not let
 * AI clients in — which is the default, and is left as the default here on
 * purpose: the tools have to be closed without anybody having said so.
 *
 * @return array{0: User, 1: Channel}
 */
function memberOutOfReach(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $channel->update(['name' => 'levering', 'slug' => 'levering']);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'De levering komt dinsdag',
    ]);

    return [$user, $channel];
}

it('does not show an AI client a workspace that has not let it in', function () {
    [$user] = memberOutOfReach();

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class)
        ->assertOk()
        ->assertDontSee('levering');
});

it('does not search a workspace that has not let an AI client in', function () {
    [$user] = memberOutOfReach();

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, ['query' => 'levering'])
        ->assertOk()
        ->assertDontSee('De levering komt dinsdag');
});

/**
 * Naming the channel outright must not get around it either — and the answer is
 * the one an unknown id gets, so the switch cannot be used to discover that a
 * channel is there.
 */
it('does not let an AI client post into a workspace that has not let it in', function () {
    [$user, $channel] = memberOutOfReach();

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, [
            'channel_id' => $channel->id,
            'body' => 'Toch even iets zeggen',
        ])
        ->assertHasErrors(['Kanaal niet gevonden.']);

    expect($channel->messages()->where('body', 'Toch even iets zeggen')->exists())->toBeFalse();
});

it('does not search a named channel in a workspace that has not let an AI client in', function () {
    [$user, $channel] = memberOutOfReach();

    ChatServer::actingAs($user)
        ->tool(SearchMessagesTool::class, [
            'query' => 'levering',
            'channel_id' => $channel->id,
        ])
        ->assertOk()
        ->assertDontSee('De levering komt dinsdag');
});

it('opens up once the workspace lets an AI client in', function () {
    [$user, $channel] = memberOutOfReach();

    Feature::for($channel->workspace)->activate(AiAccess::class);

    ChatServer::actingAs($user)
        ->tool(FindChannelsTool::class)
        ->assertOk()
        ->assertSee('levering');
});

it('refuses a webhook whose workspace switched webhooks off', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $webhook = Webhook::factory()->for($channel)->create();
    $token = $webhook->regenerateToken();
    $webhook->save();

    Feature::for($workspace)->deactivate(Webhooks::class);

    // The same 404 an unknown token gets: a different answer would confirm
    // that this one is real.
    test()->postJson(route('webhooks.messages.store', $token), ['text' => 'De build is groen'])
        ->assertNotFound();

    expect($channel->messages()->count())->toBe(0);
});
