<?php

use App\Events\MessageSent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * The test environment broadcasts over the "null" driver, whose /broadcasting/auth
 * endpoint approves every subscription — the authorisation tests below would pass
 * no matter what routes/channels.php said. So switch to the real driver.
 *
 * The re-require is not optional: Broadcast::channel() registers on whichever
 * driver is default at the moment channels.php runs, which during boot was the
 * null driver. Without this, the reverb driver has an empty channel list and
 * refuses everyone, which looks exactly like working authorisation.
 */
beforeEach(function () {
    config(['broadcasting.default' => 'reverb']);

    require base_path('routes/channels.php');
});

it('broadcasts a new message on the channel presence channel', function () {
    Event::fake([MessageSent::class]);

    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);
    $id = Str::lower((string) Str::ulid());

    actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => $id,
        'body' => 'Hallo iedereen',
    ])->assertRedirect();

    Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($id, $channel) {
        $broadcastOn = $event->broadcastOn()[0];

        return $event->message->id === $id
            && $broadcastOn instanceof PresenceChannel
            && $broadcastOn->name === 'presence-chat.channel.'.$channel->id;
    });
});

it('carries the same message shape as the page props', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'body' => 'Vergelijk mij',
    ]);

    $broadcast = (new MessageSent($message))->broadcastWith();

    $props = actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->viewData('page')['props']['messages'][0];

    expect(array_keys($broadcast['message']))->toBe(array_keys($props))
        ->and($broadcast['message']['id'])->toBe($props['id'])
        ->and($broadcast['message']['body'])->toBe('Vergelijk mij')
        ->and($broadcast['parentId'])->toBeNull()
        ->and($broadcast['channelId'])->toBe($channel->id);
});

/**
 * The same parity check for a bot message. It is the one that matters most:
 * the author is the only part of the payload that differs per sender, so if
 * the two paths are ever going to drift apart, it will be here.
 */
it('carries the same shape for a bot message as the page props', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);
    $message = Message::factory()->fromBot($webhook)->create(['body' => 'Vergelijk mij']);

    $broadcast = (new MessageSent($message))->broadcastWith();

    $props = actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->viewData('page')['props']['messages'][0];

    expect(array_keys($broadcast['message']))->toBe(array_keys($props))
        ->and($broadcast['message']['author'])->toBe($props['author'])
        ->and($props['author'])->toBe([
            'id' => null,
            'name' => 'Buildbot',
            'isBot' => true,
            'isGuest' => false,
            'avatarUrl' => null,
        ]);
});

it('authorises a member on the channel presence channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-chat.channel.'.$channel->id,
        ])
        ->assertOk();
});

it('refuses a private channel the user is not a member of', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = Channel::factory()->private()->create(['workspace_id' => $workspace->id]);

    actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-chat.channel.'.$channel->id,
        ])
        ->assertForbidden();
});

it('refuses someone outside the workspace entirely', function () {
    $outsider = User::factory()->create();
    $workspace = workspaceWithMember(User::factory()->create());
    $channel = Channel::factory()->create(['workspace_id' => $workspace->id]);

    actingAs($outsider)
        ->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-chat.channel.'.$channel->id,
        ])
        ->assertForbidden();
});

it('authorises a member on the workspace presence channel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-chat.workspace.'.$workspace->slug,
        ])
        ->assertOk();
});

/**
 * The panel is a list of the workspace's members, so membership is the whole
 * test — being able to read one channel in it is not a way onto the roster.
 */
it('refuses the workspace presence channel to somebody outside it', function () {
    $outsider = User::factory()->create();
    $workspace = workspaceWithMember(User::factory()->create());

    actingAs($outsider)
        ->post('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'presence-chat.workspace.'.$workspace->slug,
        ])
        ->assertForbidden();
});
