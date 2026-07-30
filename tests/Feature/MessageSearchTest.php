<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

it('finds messages by word', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'De deployment naar staging is klaar',
    ]);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Iemand zin in koffie',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=deployment')
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.body', 'De deployment naar staging is klaar');
});

it('never returns messages from a private channel the user cannot read', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    $secret = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);

    Message::factory()->create([
        'channel_id' => $secret->id,
        'workspace_id' => $workspace->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'Vertrouwelijke plannen',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=vertrouwelijke')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

it('never crosses the workspace boundary', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    channelWithMember($workspace, $user);

    $otherWorkspace = Workspace::factory()->create();
    $otherChannel = Channel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    Message::factory()->create([
        'channel_id' => $otherChannel->id,
        'workspace_id' => $otherWorkspace->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'Geheim van een ander bedrijf',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=geheim')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});

it('returns nothing for an empty query', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});
