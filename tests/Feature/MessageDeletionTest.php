<?php

use App\Actions\Chat\SendMessage;
use App\Events\MessageDeleted;
use App\Models\Channel;
use App\Models\Mention;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function deletionFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

function messageFrom(User $author, Workspace $workspace, Channel $channel): Message
{
    return Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $author->id,
    ]);
}

/**
 * Replies go through the real write path, because that is what keeps the
 * parent's reply_count in step — the factory only writes the reply row.
 */
function replyTo(Message $parent, User $author): Message
{
    return app(SendMessage::class)->handle(
        channel: $parent->channel,
        author: $author,
        body: 'Een antwoord van iemand anders',
        parentId: $parent->id,
    );
}

function deleteUrl(Workspace $workspace, Channel $channel, Message $message): string
{
    return route('chat.messages.destroy', [$workspace, $channel, $message]);
}

it('lets the author delete their own message', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);

    actingAs($user)
        ->delete(deleteUrl($workspace, $channel, $message))
        ->assertRedirect();

    // Soft deleted: gone from every ordinary query, still on disk so a thread
    // that hangs off it can keep working.
    expect(Message::whereKey($message->id)->exists())->toBeFalse()
        ->and(Message::withTrashed()->whereKey($message->id)->exists())->toBeTrue();
});

it('refuses to delete somebody elses message', function () {
    [$user, $workspace, $channel] = deletionFixture();

    $other = User::factory()->create();
    $workspace->members()->attach($other->id, ['role' => 'member', 'joined_at' => now()]);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    $message = messageFrom($other, $workspace, $channel);

    actingAs($user)
        ->delete(deleteUrl($workspace, $channel, $message))
        ->assertForbidden();

    expect(Message::whereKey($message->id)->exists())->toBeTrue();
});

it('refuses a message from another channel', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);
    $elsewhere = channelWithMember($workspace, $user);

    actingAs($user)
        ->delete(deleteUrl($workspace, $elsewhere, $message))
        ->assertNotFound();

    expect(Message::whereKey($message->id)->exists())->toBeTrue();
});

it('takes the mentions and reactions with it', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);

    Mention::create([
        'message_id' => $message->id,
        'user_id' => $user->id,
        'channel_id' => $channel->id,
    ]);
    Reaction::create([
        'message_id' => $message->id,
        'user_id' => $user->id,
        'emoji' => '👍',
    ]);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $message));

    expect(Mention::where('message_id', $message->id)->count())->toBe(0)
        ->and(Reaction::where('message_id', $message->id)->count())->toBe(0);
});

it('recounts the parent when a reply is deleted', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $parent = messageFrom($user, $workspace, $channel);

    $first = replyTo($parent, $user);
    replyTo($parent, $user);

    expect($parent->fresh()->reply_count)->toBe(2);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $first));

    expect($parent->fresh()->reply_count)->toBe(1);
});

it('drops a deleted message without replies from the page entirely', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $message));

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('messages', 0));
});

it('keeps a deleted thread parent as a tombstone without its text', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $parent = messageFrom($user, $workspace, $channel);
    $parent->forceFill(['body' => 'Geheim'])->save();

    replyTo($parent, $user);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $parent));

    // The row has to stay: its replies are other people's words, and this row
    // carries the only link into that thread.
    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]).'?thread='.$parent->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('messages', 1)
            ->where('messages.0.id', $parent->id)
            ->where('messages.0.body', '')
            ->where('messages.0.replyCount', 1)
            ->whereNot('messages.0.deletedAt', null)
            ->where('thread.parent.body', '')
            ->has('thread.replies', 1)
            ->where('thread.replies.0.body', 'Een antwoord van iemand anders')
        );
});

it('lets the tombstone disappear once its last reply is gone', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $parent = messageFrom($user, $workspace, $channel);
    $reply = replyTo($parent, $user);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $parent));
    actingAs($user)->delete(deleteUrl($workspace, $channel, $reply));

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('messages', 0));
});

it('cannot delete the same message twice', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $message));

    actingAs($user)
        ->delete(deleteUrl($workspace, $channel, $message))
        ->assertNotFound();
});

it('announces the deletion on the channel presence channel', function () {
    Event::fake([MessageDeleted::class]);

    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $message));

    Event::assertDispatched(MessageDeleted::class, function (MessageDeleted $event) use ($message, $channel) {
        $payload = $event->broadcastWith();
        $broadcastOn = $event->broadcastOn()[0];

        return $broadcastOn instanceof PresenceChannel
            && $broadcastOn->name === 'presence-chat.channel.'.$channel->id
            && $payload['messageId'] === $message->id
            && $payload['tombstone'] === false
            && $payload['parentReplyCount'] === null;
    });
});

it('tells subscribers when a tombstone stays behind, and what the parent now counts', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $parent = messageFrom($user, $workspace, $channel);
    $reply = replyTo($parent, $user);

    Event::fake([MessageDeleted::class]);

    actingAs($user)->delete(deleteUrl($workspace, $channel, $parent));

    Event::assertDispatched(
        MessageDeleted::class,
        fn (MessageDeleted $event) => $event->broadcastWith()['tombstone'] === true
    );

    actingAs($user)->delete(deleteUrl($workspace, $channel, $reply));

    // Deleting the reply hands out the parent's new total, so everyone's
    // "N antwoorden" line drops without a page load.
    Event::assertDispatched(function (MessageDeleted $event) use ($parent) {
        $payload = $event->broadcastWith();

        return $payload['parentId'] === $parent->id
            && $payload['parentReplyCount'] === 0;
    });
});

it('keeps a deleted message out of search results', function () {
    [$user, $workspace, $channel] = deletionFixture();
    $message = messageFrom($user, $workspace, $channel);
    $message->forceFill(['body' => 'vindbaarwoord'])->save();

    actingAs($user)->delete(deleteUrl($workspace, $channel, $message));

    actingAs($user)
        ->getJson(route('chat.search', $workspace).'?q=vindbaarwoord')
        ->assertOk()
        ->assertJsonCount(0, 'results');
});
