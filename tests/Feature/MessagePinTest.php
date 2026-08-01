<?php

use App\Actions\Chat\DeleteMessage;
use App\Enums\WorkspaceRole;
use App\Events\MessagePinned;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/**
 * A channel with somebody who runs it, which is what pinning asks for.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function pinFixture(): array
{
    $manager = User::factory()->create();
    $workspace = workspaceWithMember($manager, WorkspaceRole::Admin);
    $channel = channelWithMember($workspace, $manager);

    return [$manager, $workspace, $channel];
}

function messageIn(Channel $channel, ?User $author = null): Message
{
    return Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $channel->workspace_id,
        'user_id' => ($author ?? User::factory()->create())->id,
    ]);
}

function pinUrl(Workspace $workspace, Channel $channel, Message $message): string
{
    return route('chat.messages.pin', [$workspace, $channel, $message]);
}

/**
 * Somebody in the channel with the given role, and nothing more.
 */
function memberOf(Workspace $workspace, Channel $channel, WorkspaceRole $role): User
{
    $user = User::factory()->create();

    $workspace->members()->attach($user->id, [
        'role' => $role->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($user->id, ['joined_at' => now()]);

    return $user;
}

it('lets somebody who manages the channel pin a message', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $message = messageIn($channel);

    actingAs($manager)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertRedirect();

    $message->refresh();

    expect($message->isPinned())->toBeTrue()
        ->and($message->pinned_by)->toBe($manager->id);
});

it('lets them take it back down', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $message = messageIn($channel);
    $message->pin($manager);

    actingAs($manager)
        ->delete(route('chat.messages.unpin', [$workspace, $channel, $message]))
        ->assertRedirect();

    expect($message->refresh()->isPinned())->toBeFalse();
});

it('refuses an ordinary member', function () {
    [, $workspace, $channel] = pinFixture();
    $member = memberOf($workspace, $channel, WorkspaceRole::Member);
    $message = messageIn($channel);

    actingAs($member)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertForbidden();

    expect($message->refresh()->isPinned())->toBeFalse();
});

it('refuses a guest', function () {
    [, $workspace, $channel] = pinFixture();
    $guest = memberOf($workspace, $channel, WorkspaceRole::Guest);
    $message = messageIn($channel);

    actingAs($guest)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertForbidden();
});

it('404s on a message from another channel', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $elsewhere = channelWithMember($workspace, $manager);
    $message = messageIn($elsewhere);

    actingAs($manager)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertNotFound();
});

it('refuses in a direct message, where nobody manages anything', function () {
    [$manager, $workspace] = pinFixture();
    $dm = Channel::factory()->direct()->create(['workspace_id' => $workspace->id]);
    $dm->members()->attach($manager->id, ['joined_at' => now()]);
    $message = messageIn($dm, $manager);

    actingAs($manager)
        ->post(pinUrl($workspace, $dm, $message))
        ->assertForbidden();
});

it('refuses in an archived channel', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $channel->forceFill(['archived_at' => now()])->save();
    $message = messageIn($channel);

    actingAs($manager)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertForbidden();
});

/**
 * A tombstone is soft-deleted, so route binding never resolves it and the
 * request is a 404 before the policy is asked. MessagePolicy::pin() refuses it
 * too — that is the answer for anyone asking the ability directly, and it is
 * what keeps a "Vastpinnen" button off a tombstone.
 */
it('refuses to pin a deleted message', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $message = messageIn($channel, $manager);
    app(DeleteMessage::class)->handle($message);

    actingAs($manager)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertNotFound();

    expect($manager->can('pin', $message))->toBeFalse();
});

it('takes the pin off when the message is deleted', function () {
    [$manager, , $channel] = pinFixture();
    $message = messageIn($channel, $manager);
    $message->pin($manager);

    app(DeleteMessage::class)->handle($message);

    expect($message->refresh()->isPinned())->toBeFalse();
});

it('caps how many messages one channel keeps pinned', function () {
    [$manager, $workspace, $channel] = pinFixture();

    // Ten is the ceiling in MessagePinController; the eleventh is the one that
    // has to come back with an explanation rather than quietly not happening.
    for ($index = 0; $index < 10; $index++) {
        messageIn($channel)->pin($manager);
    }

    $overflow = messageIn($channel);

    actingAs($manager)
        ->post(pinUrl($workspace, $channel, $overflow))
        ->assertSessionHasErrors('pin');

    expect($overflow->refresh()->isPinned())->toBeFalse();
});

it('leaves an already pinned message alone', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $someoneElse = User::factory()->create();
    $message = messageIn($channel);
    $message->pin($someoneElse);
    $pinnedAt = $message->pinned_at;

    actingAs($manager)
        ->post(pinUrl($workspace, $channel, $message))
        ->assertRedirect();

    $message->refresh();

    expect($message->pinned_by)->toBe($someoneElse->id)
        ->and($message->pinned_at->toIso8601String())->toBe($pinnedAt->toIso8601String());
});

it('announces the new pin list on the channel', function () {
    Event::fake([MessagePinned::class]);

    [$manager, $workspace, $channel] = pinFixture();
    $message = messageIn($channel);

    actingAs($manager)->post(pinUrl($workspace, $channel, $message));

    Event::assertDispatched(
        MessagePinned::class,
        fn (MessagePinned $event) => $event->message->is($message)
            && $event->broadcastOn() == [new PresenceChannel('chat.channel.'.$channel->id)],
    );
});

it('sends the pins along with the channel, oldest pin first', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $first = messageIn($channel);
    $second = messageIn($channel);

    $first->pin($manager);
    $this->travel(1)->minutes();
    $second->pin($manager);

    actingAs($manager)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.canPin', true)
            ->has('pins', 2)
            ->where('pins.0.id', $first->id)
            ->where('pins.1.id', $second->id)
            ->where('pins.0.pinnedBy', $manager->name)
        );
});

it('shows a guest what is pinned without offering the button', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $message = messageIn($channel);
    $message->pin($manager);

    $guest = memberOf($workspace, $channel, WorkspaceRole::Guest);

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.canPin', false)
            ->has('pins', 1)
        );
});

it('masks blocked words in the pinned list too', function () {
    [$manager, $workspace, $channel] = pinFixture();
    $workspace->forceFill(['blocked_words' => ['geheim']])->save();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'body' => 'Dit is geheim',
    ]);
    $message->pin($manager);

    $member = memberOf($workspace, $channel, WorkspaceRole::Member);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where(
            'pins.0.snippet',
            fn (string $snippet) => ! str_contains($snippet, 'geheim'),
        ));
});
