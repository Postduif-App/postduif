<?php

use App\Actions\Chat\PresentMessage;
use App\Actions\Chat\SendMessage;
use App\Enums\SystemRole;
use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * @param  array<int, string>  $blocked
 */
function workspaceBlocking(User $user, array $blocked): Workspace
{
    $workspace = workspaceWithMember($user);
    $workspace->update(['blocked_words' => $blocked]);

    return $workspace;
}

it('masks a blocked word on the channel page', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Wat een Sukkel is dat',
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('messages.0.body', 'Wat een ****** is dat'));
});

it('masks a blocked word in the broadcast payload', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Wat een sukkel',
    ]);

    $payload = (new MessageSent($message))->broadcastWith();

    expect($payload['message']['body'])->toBe('Wat een ******');
});

it('masks a blocked word in the search results', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Die sukkel heeft de deployment gesloopt',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=deployment')
        ->assertOk()
        ->assertJsonPath('results.0.body', 'Die ****** heeft de deployment gesloopt');
});

/**
 * Censoring on the way out only pays off if the original survives: an admin
 * has to be able to see what was said, and a word added to the list tomorrow
 * has to reach everything said today.
 */
it('keeps the original text in the database', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, []);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => Str::lower((string) Str::ulid()),
        'body' => 'Wat een sukkel',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $workspace->update(['blocked_words' => ['sukkel']]);

    expect(Message::sole()->body)->toBe('Wat een sukkel');

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('messages.0.body', 'Wat een ******'));
});

it('applies the blocklist of the workspace the message belongs to', function () {
    $user = User::factory()->create();
    $strict = workspaceBlocking($user, ['sukkel']);
    $relaxed = workspaceBlocking($user, []);
    $channel = channelWithMember($relaxed, $user);

    Message::factory()->create([
        'workspace_id' => $relaxed->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Wat een sukkel',
    ]);

    expect($strict->blocked_words)->toBe(['sukkel']);

    actingAs($user)
        ->get(route('chat.show', [$relaxed, $channel]))
        ->assertInertia(fn ($page) => $page->where('messages.0.body', 'Wat een sukkel'));
});

it('asks the database for a blocklist once per page of messages', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->count(10)->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Wat een sukkel',
    ]);

    $queries = 0;
    DB::listen(function ($query) use (&$queries, $workspace) {
        if (str_contains($query->sql, 'from "workspaces"') && in_array($workspace->id, $query->bindings, true)) {
            $queries++;
        }
    });

    actingAs($user)->get(route('chat.show', [$workspace, $channel]))->assertOk();

    expect($queries)->toBeLessThan(10);
});

/**
 * Moderation applies on the way out, in PresentMessage, so a webhook gets no
 * way around it — the blocklist never had to know who sent the message.
 */
it('masks a blocked word in a message posted through a webhook', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);
    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    $message = app(SendMessage::class)
        ->fromWebhook($webhook, 'Wat een sukkel');

    expect(app(PresentMessage::class)->handle($message)['body'])
        ->not->toContain('sukkel');
});

/**
 * Masking hides the word, not the hit. Without filtering the query itself, a
 * member could type a blocked word into search and get back a list of exactly
 * who used it — which is the browsing the blocklist exists to prevent.
 */
it('does not let an ordinary member search on a blocked word', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Die sukkel heeft de deployment gesloopt',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=sukkel')
        ->assertOk()
        ->assertJsonPath('results', []);
});

it('still answers the part of the query that is not blocked', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, ['sukkel']);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Die sukkel heeft de deployment gesloopt',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q='.urlencode('sukkel deployment'))
        ->assertOk()
        ->assertJsonPath('results.0.body', 'Die ****** heeft de deployment gesloopt');
});

/**
 * The other side of the trade-off: whoever put the word on the list is the one
 * person who needs to be able to look for it.
 */
it('lets whoever runs the workspace search on a blocked word', function () {
    $owner = User::factory()->create();
    $workspace = workspaceBlocking($owner, ['sukkel']);
    $workspace->members()->updateExistingPivot($owner->id, ['workspace_role_id' => roleId($workspace, SystemRole::Owner)]);
    $channel = channelWithMember($workspace, $owner);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $owner->id,
        'body' => 'Die sukkel heeft de deployment gesloopt',
    ]);

    actingAs($owner)
        ->getJson(route('chat.search', $workspace).'?q=sukkel')
        ->assertOk()
        ->assertJsonPath('results.0.body', 'Die ****** heeft de deployment gesloopt');
});

it('leaves an ordinary search untouched when the workspace blocks nothing', function () {
    $user = User::factory()->create();
    $workspace = workspaceBlocking($user, []);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
        'body' => 'Die sukkel heeft de deployment gesloopt',
    ]);

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=sukkel')
        ->assertOk()
        ->assertJsonCount(1, 'results');
});
