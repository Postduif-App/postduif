<?php

use App\Enums\ChannelType;
use App\Enums\InboxItemType;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Somebody named in a channel they can see.
 *
 * @return array{0: User, 1: Workspace, 2: Channel, 3: Mention}
 */
function mentionFixture(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    $author = User::factory()->create();
    $channel->members()->attach($author->id, ['joined_at' => now()]);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $author->id,
        'body' => 'Kun jij hier even naar kijken?',
    ]);

    $mention = InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => $message->id,
        'user_id' => $user->id,
        'channel_id' => $channel->id,
    ]);

    return [$user, $workspace, $channel, $mention];
}

it('lists everywhere this member was named', function () {
    [$user, $workspace, $channel, $mention] = mentionFixture();

    actingAs($user)
        ->get(route('chat.mentions.index', $workspace))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('chat/inbox')
            ->has('items', 1)
            ->where('items.0.id', $mention->id)
            ->where('items.0.snippet', 'Kun jij hier even naar kijken?')
            ->where('items.0.channel.id', $channel->id)
            ->where('items.0.readAt', null));
});

it('puts what still wants an answer above what has had one', function () {
    [$user, $workspace, $channel] = mentionFixture();

    // An older mention that was already read, and a newer one that was not.
    $read = InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => Message::factory()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $channel->id,
        ])->id,
        'user_id' => $user->id,
        'channel_id' => $channel->id,
    ]);
    $read->forceFill(['read_at' => now()])->save();

    actingAs($user)
        ->get(route('chat.mentions.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('items', 2)
            ->where('items.0.readAt', null)
            ->where('items.1.id', $read->id));
});

/**
 * The row survives being removed from a channel; the line out of it must not.
 */
it('leaves out a mention from a channel this member can no longer see', function () {
    [$user, $workspace] = mentionFixture();

    $elsewhere = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
    ]);

    InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => Message::factory()->create([
            'workspace_id' => $workspace->id,
            'channel_id' => $elsewhere->id,
        ])->id,
        'user_id' => $user->id,
        'channel_id' => $elsewhere->id,
    ]);

    actingAs($user)
        ->get(route('chat.mentions.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('items', 1));
});

it('leaves out a mention whose message was taken back', function () {
    [$user, $workspace, , $mention] = mentionFixture();

    $mention->message->delete();

    actingAs($user)
        ->get(route('chat.mentions.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('items', 0));
});

it('refuses somebody who is not in the workspace', function () {
    [, $workspace] = mentionFixture();

    actingAs(User::factory()->create())
        ->get(route('chat.mentions.index', $workspace))
        ->assertForbidden();
});
