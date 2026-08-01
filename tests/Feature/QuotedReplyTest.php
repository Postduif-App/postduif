<?php

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * The payload the composer sends: the browser mints the ULID so it can draw the
 * message before the server has seen it.
 *
 * @return array<string, mixed>
 */
function quotedPayload(Message $quoted, string $body = 'Ja, ik pak hem op.'): array
{
    return [
        'id' => strtolower((string) Str::ulid()),
        'body' => $body,
        'quoted_message_id' => $quoted->id,
    ];
}

it('posts a message that quotes another one in the same channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $original = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Kan iemand de release checken?',
    ]);

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), quotedPayload($original))
        ->assertRedirect();

    $reply = Message::query()->where('quoted_message_id', $original->id)->firstOrFail();

    expect($reply->body)->toBe('Ja, ik pak hem op.')
        // A quote is an ordinary channel message: it starts no thread and moves
        // no counter on the message it answers.
        ->and($reply->parent_id)->toBeNull()
        ->and($original->refresh()->reply_count)->toBe(0);
});

it('refuses to quote a message from another channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $elsewhere = channelWithMember($workspace, $user);

    $original = Message::factory()->create([
        'channel_id' => $elsewhere->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Geheim genoeg om niet te lekken',
    ]);

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), quotedPayload($original))
        ->assertSessionHasErrors('quoted_message_id');

    expect(Message::query()->whereNotNull('quoted_message_id')->count())->toBe(0);
});

it('refuses to quote a message that is already deleted', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $original = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $original->delete();

    actingAs($user)
        ->post(route('chat.messages.store', [$workspace, $channel]), quotedPayload($original))
        ->assertSessionHasErrors('quoted_message_id');
});

it('sends the quoted message along with the page', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $original = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Kan iemand de release checken?',
    ]);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'quoted_message_id' => $original->id,
        'body' => 'Ja, ik pak hem op.',
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('messages.0.quoted', null)
            ->where('messages.1.quoted.id', $original->id)
            ->where('messages.1.quoted.author', $user->name)
            ->where('messages.1.quoted.snippet', 'Kan iemand de release checken?')
            ->where('messages.1.quoted.deleted', false)
        );
});

it('keeps the quote as a tombstone once the original is deleted', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $original = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Kan iemand de release checken?',
    ]);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'quoted_message_id' => $original->id,
    ]);

    $original->delete();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            // The reply still says it was answering something...
            ->where('messages.0.quoted.id', $original->id)
            ->where('messages.0.quoted.deleted', true)
            // ...but the words themselves stay behind.
            ->where('messages.0.quoted.snippet', '')
        );
});

it('censors a blocked word inside a quote', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $workspace->forceFill(['blocked_words' => ['rotzooi']])->save();
    $channel = channelWithMember($workspace, $user);

    $original = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Wat een rotzooi hier',
    ]);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'quoted_message_id' => $original->id,
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('messages.1.quoted.snippet', fn (string $snippet) => ! str_contains($snippet, 'rotzooi'))
        );
});
