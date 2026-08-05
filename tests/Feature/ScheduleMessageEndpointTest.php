<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\SystemRole;
use App\Models\ScheduledMessage;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('schedules a message for later', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $when = now()->addDay();

    actingAs($creator)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Fijne vrijdag iedereen',
        'send_at' => $when->toDateTimeString(),
    ])->assertRedirect();

    $scheduled = ScheduledMessage::sole();

    expect($scheduled->body)->toBe('Fijne vrijdag iedereen')
        ->and($scheduled->user_id)->toBe($creator->id)
        ->and($scheduled->send_at->toDateTimeString())->toBe($when->toDateTimeString())
        // Nothing was said yet: no message in the channel.
        ->and($channel->messages()->count())->toBe(0);
});

it('confirms that the message was parked', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Fijne vrijdag iedereen',
        'send_at' => '2030-08-12 14:00:00',
    ])->assertSessionHas('inertia.flash_data', [
        'toast' => ['type' => 'success', 'message' => 'Bericht ingepland.'],
    ]);
});

/**
 * The browser sends a real instant, offset and all. Storing it as the wall
 * clock it happens to read in the server's timezone is what used to make a
 * message go out an hour or two late.
 */
it('keeps the instant the browser sent, not the digits in it', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Om twee uur bij ons',
        // 14:00 in Amsterdam, which is 12:00 UTC.
        'send_at' => '2030-08-12T14:00:00.000+02:00',
    ]);

    expect(ScheduledMessage::sole()->send_at->utc()->toDateTimeString())
        ->toBe('2030-08-12 12:00:00');
});

it('refuses a moment that has already passed', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    actingAs($creator)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Te laat',
        'send_at' => now()->subHour()->toDateTimeString(),
    ])->assertSessionHasErrors('send_at');

    expect(ScheduledMessage::count())->toBe(0);
});

it('is not a way around a posting policy', function () {
    [, $member, $workspace, $channel] = settingsFixture();
    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    actingAs($member)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Stiekem toch',
        'send_at' => now()->addDay()->toDateTimeString(),
    ])->assertForbidden();

    expect(ScheduledMessage::count())->toBe(0);
});

it('changes what a waiting message says and when it goes', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $scheduled = ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
    ]);

    $later = now()->addDays(2);

    actingAs($creator)->patch(
        route('chat.channels.scheduled.update', [$workspace, $channel, $scheduled]),
        ['body' => 'Toch anders', 'send_at' => $later->toDateTimeString()],
    )->assertRedirect();

    $scheduled->refresh();

    expect($scheduled->body)->toBe('Toch anders')
        ->and($scheduled->send_at->toDateTimeString())->toBe($later->toDateTimeString());
});

it('takes a waiting message back', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $scheduled = ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
    ]);

    actingAs($creator)->delete(
        route('chat.channels.scheduled.destroy', [$workspace, $channel, $scheduled])
    )->assertRedirect();

    // Nobody ever saw it, so there is no history to keep.
    expect(ScheduledMessage::count())->toBe(0);
});

it('leaves somebody else their draft', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();
    $scheduled = ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
    ]);

    // Not even the channel's creator: it has not been said yet, so there is
    // nothing to moderate.
    actingAs($creator)->delete(
        route('chat.channels.scheduled.destroy', [$workspace, $channel, $scheduled])
    )->assertForbidden();

    expect(ScheduledMessage::count())->toBe(1);
});

it('refuses to change one that has already gone out', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $scheduled = ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'sent_at' => now(),
    ]);

    // There is a message in the channel now; editing that is what the message
    // edit is for.
    actingAs($creator)->patch(
        route('chat.channels.scheduled.update', [$workspace, $channel, $scheduled]),
        ['body' => 'Achteraf', 'send_at' => now()->addDay()->toDateTimeString()],
    )->assertStatus(409);

    expect($scheduled->fresh()->body)->not->toBe('Achteraf');
});

it('is a 404 for a scheduled message from another channel', function () {
    [$creator, , $workspace, $channel] = settingsFixture();
    $elsewhere = channelWithMember($workspace, $creator);

    $scheduled = ScheduledMessage::factory()->create([
        'channel_id' => $elsewhere->id,
        'user_id' => $creator->id,
    ]);

    actingAs($creator)->delete(
        route('chat.channels.scheduled.destroy', [$workspace, $channel, $scheduled])
    )->assertNotFound();
});

it('refuses scheduling by somebody who is not in the channel', function () {
    [, , $workspace, $channel] = settingsFixture();
    $stranger = User::factory()->create();
    joinWorkspace($workspace, $stranger, SystemRole::Member);

    actingAs($stranger)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Van buitenaf',
        'send_at' => now()->addDay()->toDateTimeString(),
    ])->assertForbidden();
});

it('shows the author what they still have waiting here', function () {
    [$creator, $member, $workspace, $channel] = settingsFixture();

    ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'body' => 'Van mij',
    ]);
    ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $member->id,
        'body' => 'Van iemand anders',
    ]);

    // Only your own: nothing has been said yet, so there is nothing for a
    // channel admin to see either.
    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->has('scheduled', 1)
            ->where('scheduled.0.body', 'Van mij')
        );
});

it('keeps a failed message in the list, with its reason', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'failed_at' => now(),
        'failure_reason' => 'Je mocht op dat moment niet meer posten in dit kanaal.',
    ]);

    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->has('scheduled', 1)
            ->where('scheduled.0.failureReason', 'Je mocht op dat moment niet meer posten in dit kanaal.')
        );
});

it('drops a message from the list once it has gone out', function () {
    [$creator, , $workspace, $channel] = settingsFixture();

    ScheduledMessage::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
        'sent_at' => now(),
    ]);

    // It is a message in the channel now; the list is for what is still to come.
    actingAs($creator)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('scheduled', 0));
});
