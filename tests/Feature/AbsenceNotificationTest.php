<?php

use App\Actions\Chat\ChannelPresence;
use App\Actions\Chat\MarkChannelRead;
use App\Enums\Availability;
use App\Enums\ChannelType;
use App\Enums\InboxItemType;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\InboxItem;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Notifications\ChannelActivity;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\artisan;

beforeEach(function () {
    Notification::fake();

    // Nobody has anything open unless a test says so, so presence never depends
    // on a websocket server being around.
    $presence = Mockery::mock(ChannelPresence::class);
    $presence->shouldReceive('handle')->andReturn(new Collection);
    app()->instance(ChannelPresence::class, $presence);
});

/**
 * A member who wants to hear about channels they have been away from for two
 * hours, a colleague to do the talking, and a channel they share.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function absentMember(array $overrides = []): array
{
    $member = User::factory()->create([
        'notify_after_minutes' => 120,
        'notify_via_mail' => true,
        ...$overrides,
    ]);
    $colleague = User::factory()->create();

    $workspace = workspaceWithMember($member);
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    $channel = channelWithMember($workspace, $member);
    $channel->update(['name' => 'klantproject', 'slug' => 'klantproject']);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    return [$member, $colleague, $workspace, $channel];
}

function saySomething(Channel $channel, ?User $author, string $body = 'Hoi'): Message
{
    return Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => $author?->id,
        'body' => $body,
    ]);
}

/** Pretend the member last looked at the channel this long ago. */
function lastLookedAt(Channel $channel, User $user, ?string $ago): void
{
    DB::table('channel_user')
        ->where('channel_id', $channel->id)
        ->where('user_id', $user->id)
        ->update(['last_read_at' => $ago === null ? null : now()->sub($ago)]);
}

it('tells an absent member what they missed', function () {
    [$member, $colleague, $workspace, $channel] = absentMember();

    saySomething($channel, $colleague);
    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();

    Notification::assertSentTo($member, ChannelActivity::class, function ($notification) use ($workspace, $channel) {
        return $notification->workspace->is($workspace)
            && $notification->channels->first()['channelId'] === $channel->id
            && $notification->channels->first()['unread'] === 2
            && $notification->channels->first()['label'] === '#klantproject';
    });
});

it('tells a member who left only their browser switched on', function () {
    [$member, $colleague, $workspace, $channel] = absentMember([
        'notify_via_mail' => false,
        'notify_via_push' => true,
    ]);
    PushSubscription::factory()->for($member)->create();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();

    /*
     * The half that is easy to get wrong: the command narrows to members who
     * have somewhere for a summary to arrive, and a browser is such a place.
     * Somebody who turned their mail off and their browser on used to fall out
     * of that query and never hear anything again.
     */
    Notification::assertSentTo(
        $member,
        ChannelActivity::class,
        fn (ChannelActivity $notification, array $channels): bool => $channels === [WebPushChannel::class]
            && $notification->workspace->is($workspace),
    );
});

it('gives the browser one bubble per workspace, pointing at the workspace', function () {
    [$member, $colleague, $workspace, $channel] = absentMember([
        'notify_via_mail' => false,
        'notify_via_push' => true,
    ]);
    PushSubscription::factory()->for($member)->create();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();

    Notification::assertSentTo($member, ChannelActivity::class, function (ChannelActivity $notification) use ($member, $workspace): bool {
        $message = $notification->toWebPush($member);

        // The tag is what keeps a quarter-hourly schedule from stacking three
        // stale counts of the same workspace in one tray.
        expect($message->tag)->toBe('workspace-activity-'.$workspace->id)
            ->and($message->url)->toBe(route('chat.index', $workspace))
            ->and($message->body)->toContain('#klantproject')
            // Nothing of what was said: the payload is decrypted by a push
            // service we do not run.
            ->and($message->body)->not->toContain('Hoi');

        return true;
    });
});

it('says nothing to a member who was here recently enough', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '10 minutes');

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('says nothing when the member has notifications switched off', function () {
    [$member, $colleague, , $channel] = absentMember(['notify_after_minutes' => null]);

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('never reports a member their own messages', function () {
    [$member, , , $channel] = absentMember();

    saySomething($channel, $member);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('does report what a webhook posted', function () {
    [$member, , , $channel] = absentMember();

    $webhook = Webhook::factory()->for($channel)->create(['bot_name' => 'Buildbot']);

    Message::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'channel_id' => $channel->id,
        'user_id' => null,
        'webhook_id' => $webhook->id,
        'bot_name' => 'Buildbot',
        'body' => 'De build is stuk',
    ]);

    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertSentTo($member, ChannelActivity::class);
});

/**
 * The pointer is what keeps a quarter-hourly schedule from mailing the same
 * conversation four times an hour.
 */
it('does not report the same messages twice', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');
    Notification::assertSentToTimes($member, ChannelActivity::class, 1);

    artisan('chat:notify-absent');
    Notification::assertSentToTimes($member, ChannelActivity::class, 1);
});

it('reports again once somebody says something new', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    saySomething($channel, $colleague, 'En nog wat');
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertSentToTimes($member, ChannelActivity::class, 2);
});

it('leaves a muted channel out of it', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    DB::table('channel_user')
        ->where('channel_id', $channel->id)
        ->where('user_id', $member->id)
        ->update(['muted_at' => now()]);

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('leaves an archived channel out of it', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');
    // forceFill: archived_at is deliberately outside Channel's Fillable, so
    // update() would drop it without a word.
    $channel->forceFill(['archived_at' => now()])->save();

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('says nothing to a member who is sitting in the channel right now', function () {
    [$member, $colleague, , $channel] = absentMember();

    $presence = Mockery::mock(ChannelPresence::class);
    $presence->shouldReceive('handle')->andReturn(new Collection([$member->id]));
    app()->instance(ChannelPresence::class, $presence);

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('reports a channel the member has never opened', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, null);

    artisan('chat:notify-absent');

    Notification::assertSentTo($member, ChannelActivity::class);
});

it('puts the channel that named them first', function () {
    [$member, $colleague, $workspace, $busy] = absentMember();

    $quiet = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'type' => ChannelType::Private,
        'name' => 'directie',
        'slug' => 'directie',
    ]);
    $quiet->members()->attach([$member->id, $colleague->id], ['joined_at' => now()]);

    foreach (range(1, 5) as $ignored) {
        saySomething($busy, $colleague);
    }

    $addressed = saySomething($quiet, $colleague, 'Even jij');
    InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => $addressed->id,
        'user_id' => $member->id,
        'channel_id' => $quiet->id,
    ]);

    lastLookedAt($busy, $member, '3 hours');
    lastLookedAt($quiet, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertSentTo($member, ChannelActivity::class, function ($notification) use ($quiet) {
        return $notification->channels->first()['channelId'] === $quiet->id
            && $notification->channels->first()['mentions'] === 1;
    });
});

it('skips a suspended account', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');
    $member->forceFill(['suspended_at' => now()])->save();

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('leaves the read pointer alone', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    // Being told about a message is not the same as having read it: the badge
    // in the sidebar has to survive the mail.
    expect($channel->members()->find($member->id)->pivot->last_read_message_id)->toBeNull();
});

it('stops reporting once the member reads the channel', function () {
    [$member, $colleague, , $channel] = absentMember();

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    app(MarkChannelRead::class)->handle($channel, $member);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent');

    Notification::assertNothingSent();
});

it('says nothing to a member who is on do not disturb', function () {
    [$member, $colleague, , $channel] = absentMember([
        'availability' => Availability::DoNotDisturb,
    ]);

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();

    Notification::assertNothingSent();
});

it('still talks to a member who is merely away', function () {
    [$member, $colleague, , $channel] = absentMember([
        'availability' => Availability::Away,
    ]);

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();

    Notification::assertSentTo($member, ChannelActivity::class);
});

it('keeps what happened during do not disturb waiting rather than writing it off', function () {
    [$member, $colleague, , $channel] = absentMember([
        'availability' => Availability::DoNotDisturb,
    ]);

    saySomething($channel, $colleague);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();
    Notification::assertNothingSent();

    // Back to reachable, and the summary is still owed: nothing marked the
    // message as told while nobody was being told anything.
    // forceFill, not update: availability is not mass-assignable, which is why
    // SetStatus writes it the same way.
    $member->forceFill(['availability' => Availability::Available])->save();

    artisan('chat:notify-absent')->assertSuccessful();

    Notification::assertSentTo($member, ChannelActivity::class, function ($notification) use ($channel) {
        return $notification->channels->first()['channelId'] === $channel->id
            && $notification->channels->first()['unread'] === 1;
    });
});

it('counts the mention either way, so it is there when you come back', function () {
    [$member, $colleague, , $channel] = absentMember([
        'availability' => Availability::DoNotDisturb,
    ]);

    $message = saySomething($channel, $colleague, "Kijk jij even @{$member->username}");
    InboxItem::create([
        'type' => InboxItemType::Mention,
        'message_id' => $message->id,
        'user_id' => $member->id,
        'channel_id' => $channel->id,
    ]);
    lastLookedAt($channel, $member, '3 hours');

    artisan('chat:notify-absent')->assertSuccessful();

    Notification::assertNothingSent();
    expect(InboxItem::where('user_id', $member->id)->count())->toBe(1);
});
