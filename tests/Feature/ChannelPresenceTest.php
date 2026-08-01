<?php

use App\Actions\Chat\ChannelPresence;
use App\Models\User;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;

function presenceFor(mixed $answer): ChannelPresence
{
    $pusher = Mockery::mock(Pusher::class);
    $answer instanceof Throwable
        ? $pusher->shouldReceive('get')->andThrow($answer)
        : $pusher->shouldReceive('get')->andReturn($answer);

    $broadcaster = Mockery::mock(PusherBroadcaster::class);
    $broadcaster->shouldReceive('getPusher')->andReturn($pusher);
    Broadcast::shouldReceive('driver')->andReturn($broadcaster);

    return new ChannelPresence;
}

/**
 * The Pusher SDK answers with stdClass rather than arrays. An earlier version
 * of this test handed back arrays and passed while the real thing threw, so it
 * now uses the shape the library actually returns — and the array shape too,
 * since the SDK has changed its mind about this before.
 */
it('reports who has the channel open', function (mixed $answer) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    expect(presenceFor($answer)->handle($channel)->all())->toBe([7, 12]);
})->with([
    'zoals de SDK antwoordt' => fn () => json_decode(json_encode([
        'users' => [['id' => '7'], ['id' => '12']],
    ])),
    'als array' => fn () => ['users' => [['id' => '7'], ['id' => '12']]],
]);

it('reports nobody for an empty channel', function () {
    $user = User::factory()->create();
    $channel = channelWithMember(workspaceWithMember($user), $user);

    expect(presenceFor(json_decode('{"users":[]}'))->handle($channel)->all())->toBe([]);
});

/**
 * A websocket server that is down must not take the message with it. Reporting
 * nobody means @here reaches nobody, which is quieter than the alternative of
 * assuming everyone.
 */
it('reports nobody and logs when the websocket server is unreachable', function () {
    Log::spy();

    $user = User::factory()->create();
    $channel = channelWithMember(workspaceWithMember($user), $user);

    expect(presenceFor(new RuntimeException('connection refused'))->handle($channel)->all())
        ->toBe([]);

    Log::shouldHaveReceived('warning')->once();
});

it('reports nobody when broadcasting is not going through a websocket server', function () {
    $user = User::factory()->create();
    $channel = channelWithMember(workspaceWithMember($user), $user);

    // The test suite broadcasts over the null driver; no presence to be had.
    expect((new ChannelPresence)->handle($channel)->all())->toBe([]);
});
