<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelType;
use App\Events\MessageSent;
use App\Mcp\Servers\ChatServer;
use App\Mcp\Tools\SendMessageTool;
use App\Models\Channel;
use App\Models\Mention;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;

/**
 * A member with somewhere to write.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function memberWhoCanPost(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

it('says something in a channel', function () {
    [$user, , $channel] = memberWhoCanPost();

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, [
            'channel_id' => $channel->id,
            'body' => 'De levering is verzet naar dinsdag',
        ])
        ->assertOk()
        ->assertSee('"sent":true');

    expect(Message::sole())
        ->body->toBe('De levering is verzet naar dinsdag')
        // An ordinary message from this member: no marker that a machine typed
        // it, because they asked for it to be sent.
        ->user_id->toBe($user->id)
        ->bot_name->toBeNull();
});

/**
 * Through the same action the web application uses, so the message reaches the
 * sidebars and the sockets. A plain insert would be stored and unsaid.
 */
it('reaches everybody the way an ordinary message does', function () {
    Event::fake([MessageSent::class]);

    [$user, , $channel] = memberWhoCanPost();

    ChatServer::actingAs($user)->tool(SendMessageTool::class, [
        'channel_id' => $channel->id,
        'body' => 'Hallo allemaal',
    ]);

    Event::assertDispatched(MessageSent::class);
});

it('refuses an empty message', function () {
    [$user, , $channel] = memberWhoCanPost();

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, ['channel_id' => $channel->id, 'body' => '   '])
        ->assertHasErrors();

    expect(Message::count())->toBe(0);
});

it('refuses a channel where only admins post', function () {
    [$user, , $channel] = memberWhoCanPost();

    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, ['channel_id' => $channel->id, 'body' => 'Toch even'])
        ->assertHasErrors();

    expect(Message::count())->toBe(0);
});

it('tells somebody who has not joined that that is the problem', function () {
    [$user, $workspace] = memberWhoCanPost();

    $open = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Public,
    ]);

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, ['channel_id' => $open->id, 'body' => 'Hoi'])
        ->assertSee('nog geen lid');
});

/** "No such channel" and "not yours" are deliberately the same answer. */
it('does not tell a client which channel ids exist', function () {
    [$user] = memberWhoCanPost();

    $elsewhere = Channel::factory()->create(['type' => ChannelType::Private]);

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, ['channel_id' => $elsewhere->id, 'body' => 'Hoi'])
        ->assertSee('niet gevonden');

    ChatServer::actingAs($user)
        ->tool(SendMessageTool::class, ['channel_id' => 999999, 'body' => 'Hoi'])
        ->assertSee('niet gevonden');
});

it('records a mention the same way the app does', function () {
    [$user, $workspace, $channel] = memberWhoCanPost();

    $colleague = User::factory()->create(['username' => 'fenna']);
    $workspace->members()->attach($colleague->id, ['role' => 'member', 'joined_at' => now()]);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    ChatServer::actingAs($user)->tool(SendMessageTool::class, [
        'channel_id' => $channel->id,
        'body' => 'Kun jij hiernaar kijken @fenna?',
    ]);

    expect(Mention::where('user_id', $colleague->id)->count())->toBe(1);
});
