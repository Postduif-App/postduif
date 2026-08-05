<?php

use App\Actions\Chat\FindActiveThreads;
use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * A root message with thread activity at the given age, in the given channel.
 */
function threadIn(Channel $channel, User $author, int $hoursAgo = 1, string $body = 'Bovenliggend bericht'): Message
{
    return Message::factory()
        ->withThreadActivity(now()->subHours($hoursAgo))
        ->create([
            'channel_id' => $channel->id,
            'workspace_id' => $channel->workspace_id,
            'user_id' => $author->id,
            'body' => $body,
        ]);
}

it('finds a thread whose last reply falls inside the window', function () {
    config(['chat.thread_window_hours' => 24]);

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $recent = threadIn($channel, $user, hoursAgo: 2);
    threadIn($channel, $user, hoursAgo: 30, body: 'Oud gesprek');

    $threads = app(FindActiveThreads::class)->handle($user, $workspace);

    expect($threads->pluck('id')->all())->toBe([$recent->id]);
});

it('leaves out a message without replies', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    expect(app(FindActiveThreads::class)->handle($user, $workspace))->toBeEmpty();
});

it('leaves out threads from channels the member cannot see', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = workspaceWithMember($user);
    joinWorkspace($workspace, $other, SystemRole::Member);

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);
    $private->members()->attach($other->id, ['joined_at' => now()]);

    threadIn($private, $other);

    expect(app(FindActiveThreads::class)->handle($user, $workspace))->toBeEmpty();
});

it('leaves out threads from archived channels', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $channel->forceFill(['archived_at' => now()])->save();

    threadIn($channel, $user);

    expect(app(FindActiveThreads::class)->handle($user, $workspace))->toBeEmpty();
});

it('hides a thread the member closed', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $thread = threadIn($channel, $user);
    $thread->closeFor($user);

    expect(app(FindActiveThreads::class)->handle($user, $workspace))->toBeEmpty();
});

it('keeps a closed thread visible for everybody else', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = workspaceWithMember($user);
    joinWorkspace($workspace, $other, SystemRole::Member);
    $channel = channelWithMember($workspace, $user);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $thread = threadIn($channel, $user);
    $thread->closeFor($user);

    expect(app(FindActiveThreads::class)->handle($other, $workspace)->pluck('id')->all())
        ->toBe([$thread->id]);
});

it('brings a closed thread back once somebody replies again', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $thread = threadIn($channel, $user, hoursAgo: 3);
    $thread->closeFor($user);

    expect(app(FindActiveThreads::class)->handle($user, $workspace))->toBeEmpty();

    // A reply that lands after the moment of closing — the same second would
    // count as "closed with nothing said since", which is the intended edge.
    $this->travel(1)->minutes();
    $thread->forceFill(['last_reply_at' => now(), 'reply_count' => 2])->save();

    expect(app(FindActiveThreads::class)->handle($user, $workspace)->pluck('id')->all())
        ->toBe([$thread->id]);
});

it('sends the active threads to the chat page', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $elsewhere = channelWithMember($workspace, $user);

    $thread = threadIn($elsewhere, $user, hoursAgo: 1, body: 'Waar staan we met de release?');

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('activeThreads', 1)
            ->where('activeThreads.0.id', $thread->id)
            // The row names its own channel: it is the one you are not looking at.
            ->where('activeThreads.0.channelId', $elsewhere->id)
            ->where('activeThreads.0.author', $user->name)
            ->where('activeThreads.0.snippet', 'Waar staan we met de release?')
            ->where('activeThreads.0.replyCount', 1)
        );
});

it('serves the thread list as a partial reload', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $thread = threadIn($channel, $user);

    // The asset version has to match, or Inertia answers 409 and asks the
    // browser to do a full page load instead.
    $version = app(HandleInertiaRequests::class)->version(request());

    // What the sidebar asks for when a reply arrives over the websocket. The
    // channel lists ride along because the browser drops its unread deltas the
    // moment any visit finishes.
    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Component' => 'chat/show',
            'X-Inertia-Partial-Data' => 'activeThreads,channels,directMessages',
        ])
        ->assertOk()
        // A partial reload answers with JSON rather than the page shell, so
        // this reads the props directly instead of going through assertInertia.
        ->assertJsonPath('props.activeThreads.0.id', $thread->id)
        ->assertJsonStructure(['props' => ['channels', 'directMessages']])
        // The heavy half of the page stays behind.
        ->assertJsonMissingPath('props.messages');
});

it('closes a thread for the member who asked', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $thread = threadIn($channel, $user);

    actingAs($user)
        ->post(route('chat.threads.close', [$workspace, $channel, $thread]))
        ->assertRedirect();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('activeThreads', 0));
});

it('closes a thread whose opening message was deleted', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $thread = threadIn($channel, $user);

    // The tombstone is still listed — the replies under it are somebody's
    // conversation — so the button beside it has to reach a trashed row.
    $thread->delete();

    actingAs($user)
        ->post(route('chat.threads.close', [$workspace, $channel, $thread]))
        ->assertRedirect();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('activeThreads', 0));
});

it('reopens a closed thread', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $thread = threadIn($channel, $user);
    $thread->closeFor($user);

    actingAs($user)
        ->delete(route('chat.threads.reopen', [$workspace, $channel, $thread]))
        ->assertRedirect();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('activeThreads', 1));
});

it('refuses to close a thread in a channel the member cannot see', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $workspace = workspaceWithMember($user);
    joinWorkspace($workspace, $other, SystemRole::Member);

    $private = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);
    $private->members()->attach($other->id, ['joined_at' => now()]);
    $thread = threadIn($private, $other);

    actingAs($user)
        ->post(route('chat.threads.close', [$workspace, $private, $thread]))
        ->assertForbidden();

    expect($thread->closedBy()->count())->toBe(0);
});

it('refuses a thread id from another channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $elsewhere = channelWithMember($workspace, $user);
    $thread = threadIn($elsewhere, $user);

    actingAs($user)
        ->post(route('chat.threads.close', [$workspace, $channel, $thread]))
        ->assertNotFound();
});

it('sorts the busiest thread first', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $older = threadIn($channel, $user, hoursAgo: 5);
    $newer = threadIn($channel, $user, hoursAgo: 1);

    expect(app(FindActiveThreads::class)->handle($user, $workspace)->pluck('id')->all())
        ->toBe([$newer->id, $older->id]);
});
