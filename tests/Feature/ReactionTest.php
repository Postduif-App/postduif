<?php

use App\Actions\Chat\PresentMessage;
use App\Actions\Chat\ToggleReaction;
use App\Enums\SystemRole;
use App\Events\ReactionToggled;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/**
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Message}
 */
function reactionFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    return [$user, $workspace, $channel, $message];
}

function reactionUrl(Workspace $workspace, Channel $channel, Message $message): string
{
    return route('chat.messages.reactions.store', [$workspace, $channel, $message]);
}

it('adds a reaction to a message', function () {
    [$user, $workspace, $channel, $message] = reactionFixture();

    actingAs($user)
        ->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍'])
        ->assertRedirect();

    expect($message->reactions()->pluck('emoji')->all())->toBe(['👍']);
});

it('removes the reaction when the same emoji is posted again', function () {
    [$user, $workspace, $channel, $message] = reactionFixture();

    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);
    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);

    expect($message->reactions()->count())->toBe(0);
});

it('keeps reactions from different people and different emoji apart', function () {
    [$user, $workspace, $channel, $message] = reactionFixture();

    $other = User::factory()->create();
    $workspace->members()->attach($other->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);
    $channel->members()->attach($other->id, ['joined_at' => now()]);

    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);
    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '🎉']);
    actingAs($other)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);

    expect($message->reactions()->count())->toBe(3)
        ->and($message->reactions()->where('emoji', '👍')->count())->toBe(2);
});

it('refuses a reaction from someone who is not a member of the channel', function () {
    [, $workspace, $channel, $message] = reactionFixture();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['role' => SystemRole::Member->value, 'joined_at' => now()]);

    actingAs($outsider)
        ->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍'])
        ->assertForbidden();

    expect($message->reactions()->count())->toBe(0);
});

it('refuses a reaction on a message from another channel', function () {
    [$user, $workspace, $channel, $message] = reactionFixture();

    $elsewhere = channelWithMember($workspace, $user);

    actingAs($user)
        ->post(reactionUrl($workspace, $elsewhere, $message), ['emoji' => '👍'])
        ->assertNotFound();

    expect($message->reactions()->count())->toBe(0);
});

it('rejects anything that is not an emoji', function (string $emoji) {
    [$user, $workspace, $channel, $message] = reactionFixture();

    actingAs($user)
        ->post(reactionUrl($workspace, $channel, $message), ['emoji' => $emoji])
        ->assertSessionHasErrors('emoji');

    expect($message->reactions()->count())->toBe(0);
})->with([
    'text' => 'lgtm',
    'a digit' => '7',
    'whitespace' => '👍 👍',
    'too long' => str_repeat('👍', 33),
    'empty' => '',
]);

it('leaves nothing behind in the page props once a reaction is taken away', function () {
    [$user, $workspace, $channel, $message] = reactionFixture();

    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('messages.0.reactions', 1));

    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);

    // The browser falls back on these props when it drops its own optimistic
    // draft, so an emptied set here is what actually makes the pill stay gone.
    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('messages.0.reactions', 0));
});

it('reports which way it toggled', function () {
    [$user, , , $message] = reactionFixture();

    $toggle = app(ToggleReaction::class);

    expect($toggle->handle($message, $user, '👍'))->toBeTrue()
        ->and($toggle->handle($message, $user, '👍'))->toBeFalse();
});

it('summarises reactions with the ids of everyone behind them', function () {
    [$user, , , $message] = reactionFixture();

    $other = User::factory()->create();

    Reaction::create(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => '👍']);
    Reaction::create(['message_id' => $message->id, 'user_id' => $other->id, 'emoji' => '👍']);
    Reaction::create(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => '🎉']);

    expect(app(PresentMessage::class)->reactions($message))->toBe([
        ['emoji' => '👍', 'count' => 2, 'userIds' => [$user->id, $other->id]],
        ['emoji' => '🎉', 'count' => 1, 'userIds' => [$user->id]],
    ]);
});

it('broadcasts the complete reaction set in both directions', function () {
    Event::fake([ReactionToggled::class]);

    [$user, $workspace, $channel, $message] = reactionFixture();

    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);

    Event::assertDispatched(ReactionToggled::class, function (ReactionToggled $event) use ($message, $channel, $user) {
        $payload = $event->broadcastWith();
        $broadcastOn = $event->broadcastOn()[0];

        return $broadcastOn instanceof PresenceChannel
            && $broadcastOn->name === 'presence-chat.channel.'.$channel->id
            && $payload['messageId'] === $message->id
            && $payload['reactions'] === [
                ['emoji' => '👍', 'count' => 1, 'userIds' => [$user->id]],
            ];
    });

    // Taking the reaction off announces an empty set rather than staying quiet,
    // so the last pill actually disappears from other people's screens.
    actingAs($user)->post(reactionUrl($workspace, $channel, $message), ['emoji' => '👍']);

    Event::assertDispatched(
        ReactionToggled::class,
        fn (ReactionToggled $event) => $event->broadcastWith()['reactions'] === []
    );
});
