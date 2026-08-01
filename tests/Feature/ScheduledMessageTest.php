<?php

use App\Actions\Chat\DispatchScheduledMessages;
use App\Enums\ChannelPostingPolicy;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;

it('posts a message once its moment has come', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'body' => 'Fijne vrijdag iedereen',
    ]);

    expect(app(DispatchScheduledMessages::class)->handle())
        ->toBe(['sent' => 1, 'failed' => 0]);

    $message = Message::sole();

    expect($message->body)->toBe('Fijne vrijdag iedereen')
        ->and($message->user_id)->toBe($creator->id)
        ->and($message->channel_id)->toBe($channel->id)
        ->and(ScheduledMessage::sole()->sent_at)->not->toBeNull();
});

it('leaves a message whose moment has not arrived alone', function () {
    [$creator, , , $channel] = settingsFixture();

    ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'send_at' => now()->addHour(),
    ]);

    expect(app(DispatchScheduledMessages::class)->handle())
        ->toBe(['sent' => 0, 'failed' => 0])
        ->and(Message::count())->toBe(0);
});

it('never says the same message twice', function () {
    [$creator, , , $channel] = settingsFixture();

    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
    ]);

    app(DispatchScheduledMessages::class)->handle();
    app(DispatchScheduledMessages::class)->handle();

    expect(Message::count())->toBe(1);
});

it('refuses to post for somebody who may no longer post there', function () {
    [$creator, $member, , $channel] = settingsFixture();

    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);

    // A week is long enough for a channel to become an announcement channel.
    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    expect(app(DispatchScheduledMessages::class)->handle())
        ->toBe(['sent' => 0, 'failed' => 1])
        ->and(Message::count())->toBe(0);

    $scheduled = ScheduledMessage::sole();

    // Marked rather than dropped: a message that silently never arrives is
    // worse than one that says why it did not.
    expect($scheduled->sent_at)->toBeNull()
        ->and($scheduled->failed_at)->not->toBeNull()
        ->and($scheduled->failure_reason)->toContain('niet meer posten');
});

it('does not retry something it already gave up on', function () {
    [$creator, , , $channel] = settingsFixture();

    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'failed_at' => now(),
        'failure_reason' => 'Eerder misgegaan',
    ]);

    expect(app(DispatchScheduledMessages::class)->handle())
        ->toBe(['sent' => 0, 'failed' => 0])
        ->and(Message::count())->toBe(0);
});

it('fails the one it cannot send and still sends the rest', function () {
    [$creator, $member, , $channel] = settingsFixture();

    $stranger = User::factory()->create();

    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'body' => 'Deze gaat wel',
    ]);
    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        // Never joined the channel, so posting is refused at send time.
        'user_id' => $stranger->id,
        'body' => 'Deze niet',
    ]);

    expect(app(DispatchScheduledMessages::class)->handle())
        ->toBe(['sent' => 1, 'failed' => 1])
        ->and(Message::sole()->body)->toBe('Deze gaat wel');
});

it('goes out through the ordinary send, so it counts as unread', function () {
    [$creator, $member, , $channel] = settingsFixture();

    ScheduledMessage::factory()->due()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
    ]);

    app(DispatchScheduledMessages::class)->handle();

    // The whole reason it uses SendMessage: everything hanging off a message —
    // unread counts, mentions, broadcasts — happens exactly as it always does.
    expect($channel->fresh()->messages()->count())->toBe(1)
        ->and(Message::sole()->created_at)->not->toBeNull();
});

it('takes the scheduled messages with the channel when it is deleted', function () {
    [$creator, , , $channel] = settingsFixture();

    ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
    ]);

    $channel->delete();

    expect(ScheduledMessage::count())->toBe(0);
});
