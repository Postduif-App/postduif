<?php

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

it('sends no thread when the url names none', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('thread', null));
});

it('opens a thread named in the url', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Bovenliggend bericht',
    ]);

    Message::factory()->inThread($parent)->create([
        'user_id' => $user->id,
        'body' => 'Eerste antwoord',
    ]);
    Message::factory()->inThread($parent)->create([
        'user_id' => $user->id,
        'body' => 'Tweede antwoord',
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]).'?thread='.$parent->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('thread.parent.id', $parent->id)
            ->where('thread.parent.body', 'Bovenliggend bericht')
            ->has('thread.replies', 2)
            ->where('thread.replies.0.body', 'Eerste antwoord')
            ->where('thread.replies.1.body', 'Tweede antwoord')
            ->where('thread.replies.0.parentId', $parent->id)
            // The channel pane still shows only the root message.
            ->has('messages', 1)
        );
});

it('ignores a thread id from another channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $other = channelWithMember($workspace, $user);

    $elsewhere = Message::factory()->create([
        'channel_id' => $other->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]).'?thread='.$elsewhere->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('thread', null));
});

it('ignores a thread id that is itself a reply', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $reply = Message::factory()->inThread($parent)->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]).'?thread='.$reply->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('thread', null));
});

it('broadcasts the parents new total alongside a reply', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    Message::factory()->inThread($parent)->create(['user_id' => $user->id]);
    $parent->increment('reply_count');

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => (string) Str::ulid(),
        'body' => 'Nog een antwoord',
        'parent_id' => $parent->id,
    ])->assertRedirect();

    $payload = (new MessageSent(Message::latest('id')->first()))->broadcastWith();

    expect($payload['parentId'])->toBe($parent->id)
        ->and($payload['parentReplyCount'])->toBe(2)
        ->and($parent->fresh()->reply_count)->toBe(2);
});

it('sends no parent total for a root message', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $payload = (new MessageSent($message))->broadcastWith();

    expect($payload['parentId'])->toBeNull()
        ->and($payload['parentReplyCount'])->toBeNull();
});

it('refuses a thread in a channel the user may not read', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => User::factory()->create()->id,
    ]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]).'?thread='.$parent->id)
        ->assertForbidden();
});
