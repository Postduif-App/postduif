<?php

use App\Actions\Chat\PresentMessage;
use App\Actions\Chat\SendMessage;
use App\Events\ChannelActivity;
use App\Events\MessageSent;
use App\Models\Mention;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Event;

/**
 * A channel with two members and a webhook pointed at it.
 *
 * @return array{0: User, 1: User, 2: Webhook}
 */
function channelWithWebhook(): array
{
    $first = User::factory()->create(['username' => 'fenna']);
    $second = User::factory()->create(['username' => 'joris']);

    $workspace = workspaceWithMember($first);
    $workspace->members()->attach($second->id, ['role' => 'member', 'joined_at' => now()]);

    $channel = channelWithMember($workspace, $first);
    $channel->members()->attach($second->id, ['joined_at' => now()]);

    return [$first, $second, Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot'])];
}

it('posts a message through a webhook under its bot name', function () {
    [, , $webhook] = channelWithWebhook();

    $message = app(SendMessage::class)->fromWebhook($webhook, 'De build is groen');

    expect($message->channel_id)->toBe($webhook->channel_id)
        ->and($message->workspace_id)->toBe($webhook->workspace_id)
        ->and($message->user_id)->toBeNull()
        ->and($message->bot_name)->toBe('Buildbot')
        ->and($message->isFromBot())->toBeTrue();
});

it('broadcasts a bot message to the channel', function () {
    Event::fake([MessageSent::class, ChannelActivity::class]);

    [, , $webhook] = channelWithWebhook();

    app(SendMessage::class)->fromWebhook($webhook, 'De build is groen');

    Event::assertDispatched(MessageSent::class);
});

it('presents a bot message with its bot name and no member id', function () {
    [, , $webhook] = channelWithWebhook();

    $message = app(SendMessage::class)->fromWebhook($webhook, 'De build is groen');

    expect(app(PresentMessage::class)->handle($message)['author'])->toBe([
        'id' => null,
        'name' => 'Buildbot',
        'isBot' => true,
        'isGuest' => false,
        'avatarUrl' => null,
    ]);
});

it('marks a message from a member as not being from a bot', function () {
    [$first, , $webhook] = channelWithWebhook();

    $message = app(SendMessage::class)->handle($webhook->channel, $first, 'Hoi');

    expect(app(PresentMessage::class)->handle($message)['author'])->toBe([
        'id' => $first->id,
        'name' => $first->name,
        'isBot' => false,
        'isGuest' => false,
        'avatarUrl' => null,
    ]);
});

/**
 * A member is left out of the sidebar nudge for their own message. A webhook
 * is nobody's own message, so nobody gets left out.
 */
it('nudges every member of the channel, excluding no one', function () {
    Event::fake([ChannelActivity::class]);

    [$first, $second, $webhook] = channelWithWebhook();

    app(SendMessage::class)->fromWebhook($webhook, 'De build is groen');

    Event::assertDispatchedTimes(ChannelActivity::class, 2);

    foreach ([$first, $second] as $member) {
        Event::assertDispatched(
            ChannelActivity::class,
            fn (ChannelActivity $event) => $event->userId === $member->id,
        );
    }
});

it('advances nobody\'s read marker when a webhook posts', function () {
    [$first, , $webhook] = channelWithWebhook();

    app(SendMessage::class)->fromWebhook($webhook, 'De build is groen');

    expect($webhook->channel->members()->find($first->id)?->pivot->last_read_message_id)
        ->toBeNull();
});

/**
 * The exclusion of the sender is expressed as "not this user id", which for a
 * webhook is "not null" — and in SQL that quietly matches nobody. So this is
 * really a test that a bot can mention anyone at all.
 */
it('lets a bot mention a member', function () {
    [$first, , $webhook] = channelWithWebhook();

    $message = app(SendMessage::class)->fromWebhook($webhook, 'Kijk even @fenna');

    expect(Mention::where('message_id', $message->id)->pluck('user_id')->all())
        ->toBe([$first->id]);
});

it('refuses to let a bot summon the whole channel', function () {
    [, , $webhook] = channelWithWebhook();

    $message = app(SendMessage::class)->fromWebhook($webhook, 'Let op @everyone');

    expect(Mention::where('message_id', $message->id)->exists())->toBeFalse();
});

it('bumps the channel and the thread counters like any other message', function () {
    [$first, , $webhook] = channelWithWebhook();

    $parent = app(SendMessage::class)->handle($webhook->channel, $first, 'Vraag');
    $reply = app(SendMessage::class)->fromWebhook($webhook, 'Antwoord', parentId: $parent->id);

    expect($reply->parent_id)->toBe($parent->id)
        ->and($parent->fresh()->reply_count)->toBe(1)
        ->and($webhook->channel->fresh()->last_message_at)->not->toBeNull();
});
