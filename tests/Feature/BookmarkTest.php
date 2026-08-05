<?php

use App\Enums\SystemRole;
use App\Models\Bookmark;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A message somebody might want to come back to.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Message}
 */
function savableMessage(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'body' => 'Hier moet ik nog op terugkomen',
    ]);

    return [$user, $workspace, $channel, $message];
}

it('sets a message aside', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    actingAs($user)
        ->post(route('chat.messages.bookmark', [$workspace, $channel, $message]))
        ->assertRedirect();

    expect(Bookmark::sole())
        ->user_id->toBe($user->id)
        ->message_id->toBe($message->id)
        ->channel_id->toBe($channel->id);
});

/** Saving the same message twice is the same act, not a second one. */
it('does not save the same message twice', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]));
    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]))
        ->assertRedirect();

    expect(Bookmark::count())->toBe(1);
});

it('takes it off the list again', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]));
    actingAs($user)->delete(route('chat.messages.unbookmark', [$workspace, $channel, $message]))
        ->assertRedirect();

    expect(Bookmark::count())->toBe(0);
});

it('refuses somebody who cannot see the channel', function () {
    [, $workspace, $channel, $message] = savableMessage();

    $channel->update(['type' => 'private']);

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);

    actingAs($outsider)
        ->post(route('chat.messages.bookmark', [$workspace, $channel, $message]))
        ->assertForbidden();
});

it('lists what was set aside, newest first', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    $older = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
    ]);

    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $older]));
    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]));

    actingAs($user)
        ->get(route('chat.saved.index', $workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('chat/saved')
            ->has('saved', 2)
            ->where('saved.0.messageId', $message->id)
            ->where('saved.0.snippet', 'Hier moet ik nog op terugkomen')
            ->where('saved.1.messageId', $older->id));
});

it('tells the channel page which of its messages are saved', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]));

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('bookmarkedIds', [$message->id]));
});

it('leaves out a saved message that was taken back', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    actingAs($user)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]));
    $message->delete();

    actingAs($user)
        ->get(route('chat.saved.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('saved', 0));
});

/** Somebody else's list is not something this member has any window into. */
it('shows only your own', function () {
    [$user, $workspace, $channel, $message] = savableMessage();

    $colleague = User::factory()->create();
    joinWorkspace($workspace, $colleague, SystemRole::Member);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($colleague)->post(route('chat.messages.bookmark', [$workspace, $channel, $message]));

    actingAs($user)
        ->get(route('chat.saved.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('saved', 0));
});
