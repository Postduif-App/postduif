<?php

use App\Actions\Chat\ChannelPresence;
use App\Actions\Chat\SendMessage;
use App\Enums\Availability;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\NewChannelMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Notification::fake();

    // Nobody has anything open unless a test says so, so presence never
    // depends on a websocket server being around.
    $presence = Mockery::mock(ChannelPresence::class);
    $presence->shouldReceive('handle')->andReturn(new Collection);
    app()->instance(ChannelPresence::class, $presence);
});

/**
 * A reader with a browser subscribed, an author to share a channel with, and
 * the channel itself.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function instantReader(array $overrides = []): array
{
    $reader = User::factory()->create([
        'notify_via_push' => true,
        ...$overrides,
    ]);
    PushSubscription::factory()->for($reader)->create();

    $author = User::factory()->create();

    $workspace = workspaceWithMember($reader);
    joinWorkspace($workspace, $author, SystemRole::Member);

    $channel = channelWithMember($workspace, $reader);
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    return [$reader, $author, $workspace, $channel];
}

it('pushes right away for a channel set to instant', function () {
    [$reader, $author, , $channel] = instantReader();
    $channel->members()->updateExistingPivot($reader->id, ['instant_notifications' => true]);

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertSentTo($reader, NewChannelMessage::class);
});

it('leaves an ordinary channel alone', function () {
    [$reader, $author, , $channel] = instantReader();

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertNotSentTo($reader, NewChannelMessage::class);
});

it('follows the account default when a channel has no override of its own', function () {
    [$reader, $author, , $channel] = instantReader(['notify_instantly_by_default' => true]);

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertSentTo($reader, NewChannelMessage::class);
});

it('lets one channel opt out of the account default', function () {
    [$reader, $author, , $channel] = instantReader(['notify_instantly_by_default' => true]);
    $channel->members()->updateExistingPivot($reader->id, ['instant_notifications' => false]);

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertNotSentTo($reader, NewChannelMessage::class);
});

it('lets a mute win over instant notifications', function () {
    [$reader, $author, , $channel] = instantReader();
    $channel->members()->updateExistingPivot($reader->id, [
        'instant_notifications' => true,
        'muted_at' => now(),
    ]);

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertNotSentTo($reader, NewChannelMessage::class);
});

it('does not interrupt somebody already watching the channel', function () {
    [$reader, $author, , $channel] = instantReader();
    $channel->members()->updateExistingPivot($reader->id, ['instant_notifications' => true]);

    $presence = Mockery::mock(ChannelPresence::class);
    $presence->shouldReceive('handle')->andReturn(new Collection([$reader->id]));
    app()->instance(ChannelPresence::class, $presence);

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertNotSentTo($reader, NewChannelMessage::class);
});

it('respects do not disturb', function () {
    [$reader, $author, , $channel] = instantReader(['availability' => Availability::DoNotDisturb]);
    $channel->members()->updateExistingPivot($reader->id, ['instant_notifications' => true]);

    app(SendMessage::class)->handle($channel, $author, 'Hoi');

    Notification::assertNotSentTo($reader, NewChannelMessage::class);
});

it('never pushes the author their own message', function () {
    [$reader, , $workspace, $channel] = instantReader();
    $channel->members()->updateExistingPivot($reader->id, ['instant_notifications' => true]);

    app(SendMessage::class)->handle($channel, $reader, 'Hoi');

    Notification::assertNothingSent();
});

it('changes the channel override through the endpoint', function () {
    [$reader, , $workspace, $channel] = instantReader();

    actingAs($reader)
        ->put(route('chat.channels.instant-notifications', [$workspace, $channel]), ['instant' => true])
        ->assertRedirect();

    expect($channel->members()->find($reader->id)->pivot->instant_notifications)->toBeTrue();

    actingAs($reader)
        ->put(route('chat.channels.instant-notifications', [$workspace, $channel]), [])
        ->assertRedirect();

    expect($channel->members()->find($reader->id)->pivot->instant_notifications)->toBeNull();
});
