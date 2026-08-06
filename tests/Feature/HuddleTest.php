<?php

use App\Actions\Huddles\JoinHuddle;
use App\Actions\Huddles\LeaveHuddle;
use App\Actions\Huddles\SweepStaleHuddles;
use App\Enums\ChannelPostingPolicy;
use App\Enums\SystemRole;
use App\Events\HuddleUpdated;
use App\Features\Huddles as HuddlesFeature;
use App\Models\Channel;
use App\Models\Huddle;
use App\Models\HuddleParticipant;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

/**
 * Who is in the conversation in a channel. Nothing here is about audio — the
 * browsers arrange that between themselves over the presence channel they are
 * already on; this is the part that has to agree with everybody.
 */
it('is off until a workspace asks for it', function () {
    // Audio between two networks needs a relay arranged first; a button that
    // connects for some people and silently does not for the rest is worse
    // than no button.
    expect(HuddlesFeature::default())->toBeFalse();
});

it('starts a huddle when there is none, and puts the starter in it', function () {
    [$member, , $workspace, $channel] = huddleFixture();

    actingAs($member)
        ->from(route('chat.show', [$workspace, $channel]))
        ->post(route('chat.huddles.store', [$workspace, $channel]))
        ->assertRedirect();

    $huddle = Huddle::sole();

    expect($huddle->channel_id)->toBe($channel->id)
        ->and($huddle->started_by)->toBe($member->id)
        ->and($huddle->isLive())->toBeTrue()
        ->and($huddle->present()->pluck('user_id')->all())->toBe([$member->id]);
});

it('joins the one that is already going rather than starting a second', function () {
    [$member, $guest, $workspace, $channel] = huddleFixture();

    actingAs($member)->post(route('chat.huddles.store', [$workspace, $channel]));
    actingAs($guest)->post(route('chat.huddles.store', [$workspace, $channel]));

    $huddle = Huddle::sole();

    expect($huddle->present()->pluck('user_id')->all())
        ->toEqualCanonicalizing([$member->id, $guest->id]);
});

it('lets somebody who dropped out come back without a second place in the list', function () {
    [$member, , , $channel] = huddleFixture();

    $huddle = app(JoinHuddle::class)->handle($channel, $member);
    app(LeaveHuddle::class)->handle($huddle, $member);

    // The huddle closed behind them; joining again starts a new one.
    $second = app(JoinHuddle::class)->handle($channel, $member);
    app(JoinHuddle::class)->handle($channel, $member);

    expect(HuddleParticipant::where('huddle_id', $second->id)->count())->toBe(1)
        ->and($second->present()->count())->toBe(1);
});

it('closes the huddle behind the last person out', function () {
    [$member, $guest, , $channel] = huddleFixture();

    $huddle = app(JoinHuddle::class)->handle($channel, $member);
    app(JoinHuddle::class)->handle($channel, $guest);

    app(LeaveHuddle::class)->handle($huddle, $member);

    expect($huddle->fresh()->isLive())->toBeTrue();

    app(LeaveHuddle::class)->handle($huddle, $guest);

    expect($huddle->fresh()->isLive())->toBeFalse()
        ->and($huddle->fresh()->ended_at)->not->toBeNull();
});

it('starts a fresh huddle once the last one is over', function () {
    [$member, , , $channel] = huddleFixture();

    $first = app(JoinHuddle::class)->handle($channel, $member);
    app(LeaveHuddle::class)->handle($first, $member);

    $second = app(JoinHuddle::class)->handle($channel, $member);

    expect($second->id)->not->toBe($first->id)
        // The one that is over stays: it is the record of a conversation that
        // happened, and "wie was erbij" has to still have an answer.
        ->and(Huddle::count())->toBe(2);
});

it('refuses somebody who may not post in the channel', function () {
    [, , $workspace, $channel] = huddleFixture();

    $reader = User::factory()->create();
    joinWorkspace($workspace, $reader, SystemRole::Member);
    $channel->update(['posting_policy' => ChannelPostingPolicy::Admins]);

    actingAs($reader)
        ->post(route('chat.huddles.store', [$workspace, $channel]))
        ->assertForbidden();

    expect(Huddle::count())->toBe(0);
});

it('refuses a huddle in an archived channel', function () {
    [$member, , $workspace, $channel] = huddleFixture();
    // forceFill rather than update: archived_at is deliberately outside the
    // model's Fillable, so an update() here would quietly do nothing.
    $channel->forceFill(['archived_at' => now()])->save();

    actingAs($member)
        ->post(route('chat.huddles.store', [$workspace, $channel]))
        ->assertForbidden();
});

it('is not there at all for a workspace that has not switched it on', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)
        ->post(route('chat.huddles.store', [$workspace, $channel]))
        ->assertNotFound();
});

it('tells the channel who is in it', function () {
    [$member, , , $channel] = huddleFixture();
    Event::fake([HuddleUpdated::class]);

    app(JoinHuddle::class)->handle($channel, $member);

    Event::assertDispatched(HuddleUpdated::class, function (HuddleUpdated $event) use ($channel, $member): bool {
        $payload = $event->broadcastWith();

        return $payload['channelId'] === $channel->id
            && $payload['live'] === true
            && $payload['participants'][0]['id'] === $member->id;
    });
});

it('leaves the huddle of another channel alone', function () {
    [$member, , $workspace, $channel] = huddleFixture();
    $elsewhere = channelWithMember($workspace, $member);
    $theirs = Huddle::factory()->create(['channel_id' => $elsewhere->id]);

    actingAs($member)
        ->delete(route('chat.huddles.destroy', [$workspace, $channel, $theirs]))
        ->assertNotFound();
});

it('sends the huddle going on with the page, to everybody who can see the channel', function () {
    [$member, $guest, $workspace, $channel] = huddleFixture();

    app(JoinHuddle::class)->handle($channel, $member);

    // The guest may not have started it, but seeing that it is happening is
    // what makes walking in possible at all.
    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.huddle.live', true)
            ->where('channel.huddle.participants.0.id', $member->id));
});

it('says nothing about huddles on a workspace that has them switched off', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.huddle', null)
            ->where('channel.canHuddle', false));
});

it('shows in the sidebar that a channel has people talking in it', function () {
    [$member, $guest, $workspace, $channel] = huddleFixture();
    $elsewhere = channelWithMember($workspace, $member);

    app(JoinHuddle::class)->handle($channel, $member);
    app(JoinHuddle::class)->handle($channel, $guest);

    actingAs($member)
        ->get(route('chat.show', [$workspace, $elsewhere]))
        ->assertInertia(fn ($page) => $page
            ->where(
                'channels',
                fn (Collection $rows) => $rows
                    ->firstWhere('id', $channel->id)['huddleCount'] === 2
                    && $rows->firstWhere('id', $elsewhere->id)['huddleCount'] === 0,
            ));
});

it('leaves the sidebar counting nothing where huddles are switched off', function () {
    [$member, , $workspace, $channel] = ticketFixture();

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where(
                'channels',
                fn (Collection $rows) => $rows
                    ->every(fn (array $row): bool => $row['huddleCount'] === 0),
            ));
});

it('sweeps somebody a huddle has stopped hearing from, and closes what is left', function () {
    [$member, $guest, , $channel] = huddleFixture();

    $huddle = app(JoinHuddle::class)->handle($channel, $member);
    app(JoinHuddle::class)->handle($channel, $guest);

    // The member's browser died: no goodbye, and no heartbeat since.
    HuddleParticipant::where('huddle_id', $huddle->id)
        ->where('user_id', $member->id)
        ->update([
            'last_seen_at' => now()->subSeconds(SweepStaleHuddles::AFTER_SECONDS + 1),
        ]);

    expect(app(SweepStaleHuddles::class)->handle())->toBe(0)
        ->and($huddle->fresh()->present()->pluck('user_id')->all())->toBe([$guest->id])
        // The huddle carries on: somebody is still in it.
        ->and($huddle->fresh()->isLive())->toBeTrue();
});

it('closes a huddle once the last browser has gone quiet', function () {
    [$member, , , $channel] = huddleFixture();

    $huddle = app(JoinHuddle::class)->handle($channel, $member);

    HuddleParticipant::where('huddle_id', $huddle->id)->update([
        'last_seen_at' => now()->subSeconds(SweepStaleHuddles::AFTER_SECONDS + 1),
    ]);

    expect(app(SweepStaleHuddles::class)->handle())->toBe(1)
        ->and($huddle->fresh()->isLive())->toBeFalse();
});

it('leaves a huddle alone while its people keep saying they are there', function () {
    [$member, , , $channel] = huddleFixture();

    $huddle = app(JoinHuddle::class)->handle($channel, $member);

    // A tab the browser throttled, or a laptop that slept for a moment: quiet
    // for a while, but not long enough to count as gone.
    HuddleParticipant::where('huddle_id', $huddle->id)->update([
        'last_seen_at' => now()->subSeconds(SweepStaleHuddles::AFTER_SECONDS - 10),
    ]);

    expect(app(SweepStaleHuddles::class)->handle())->toBe(0)
        ->and($huddle->fresh()->isLive())->toBeTrue();
});

it('takes a heartbeat as a sign of life', function () {
    [$member, , $workspace, $channel] = huddleFixture();
    $huddle = app(JoinHuddle::class)->handle($channel, $member);

    HuddleParticipant::where('huddle_id', $huddle->id)->update([
        'last_seen_at' => now()->subMinutes(5),
    ]);

    actingAs($member)
        ->patch(route('chat.huddles.ping', [$workspace, $channel, $huddle]))
        ->assertNoContent();

    expect(app(SweepStaleHuddles::class)->handle())->toBe(0)
        ->and($huddle->fresh()->isLive())->toBeTrue();
});

it('frees the channel for a new huddle once the ghost is swept', function () {
    [$member, , , $channel] = huddleFixture();

    $stuck = app(JoinHuddle::class)->handle($channel, $member);
    HuddleParticipant::where('huddle_id', $stuck->id)->update([
        'last_seen_at' => now()->subSeconds(SweepStaleHuddles::AFTER_SECONDS + 1),
    ]);

    app(SweepStaleHuddles::class)->handle();

    // The whole point of the sweep: a channel may hold one live huddle, so a
    // ghost would otherwise block it for good.
    $fresh = app(JoinHuddle::class)->handle($channel, $member);

    expect($fresh->id)->not->toBe($stuck->id)
        ->and($fresh->isLive())->toBeTrue();
});
