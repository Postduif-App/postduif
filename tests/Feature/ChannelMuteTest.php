<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\ChannelMembership;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A channel with somebody in it who can decide to be left alone.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function channelToQuieten(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

function membershipOf(Channel $channel, User $user): ChannelMembership
{
    return $channel->loadMissing('members')->membershipFor($user);
}

it('quietens a channel until further notice', function () {
    [$user, $workspace, $channel] = channelToQuieten();

    actingAs($user)
        ->post(route('chat.channels.mute', [$workspace, $channel]))
        ->assertRedirect();

    $membership = membershipOf($channel->fresh(), $user);

    expect($membership->muted_at)->not->toBeNull()
        ->and($membership->muted_until)->toBeNull()
        ->and($membership->isMuted())->toBeTrue();
});

it('quietens a channel for a stretch of hours', function () {
    [$user, $workspace, $channel] = channelToQuieten();

    actingAs($user)->post(route('chat.channels.mute', [$workspace, $channel]), ['hours' => 8]);

    $membership = membershipOf($channel->fresh(), $user);

    expect($membership->muted_until->isSameDay(now()->addHours(8)))->toBeTrue()
        ->and($membership->isMuted())->toBeTrue();
});

/**
 * A mute with a date simply stops mattering when the date passes; nothing has
 * to go and clear the column.
 */
it('stops being muted once the stretch has passed', function () {
    [$user, , $channel] = channelToQuieten();

    $channel->members()->updateExistingPivot($user->id, [
        'muted_at' => now()->subDay(),
        'muted_until' => now()->subHour(),
    ]);

    expect(membershipOf($channel->fresh(), $user)->isMuted())->toBeFalse();
});

it('turns the sound back on', function () {
    [$user, $workspace, $channel] = channelToQuieten();

    actingAs($user)->post(route('chat.channels.mute', [$workspace, $channel]), ['hours' => 8]);
    actingAs($user)->delete(route('chat.channels.unmute', [$workspace, $channel]))->assertRedirect();

    $membership = membershipOf($channel->fresh(), $user);

    expect($membership->muted_at)->toBeNull()
        ->and($membership->muted_until)->toBeNull();
});

it('refuses somebody who is not in the channel', function () {
    [, $workspace, $channel] = channelToQuieten();

    $outsider = User::factory()->create();
    joinWorkspace($workspace, $outsider, SystemRole::Member);

    actingAs($outsider)
        ->post(route('chat.channels.mute', [$workspace, $channel]))
        ->assertForbidden();
});

it('refuses a stretch longer than a week', function () {
    [$user, $workspace, $channel] = channelToQuieten();

    actingAs($user)
        ->post(route('chat.channels.mute', [$workspace, $channel]), ['hours' => 1000])
        ->assertSessionHasErrors('hours');
});

it('tells the page whether the channel is quiet', function () {
    [$user, $workspace, $channel] = channelToQuieten();

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.mutedUntil', null));

    actingAs($user)->post(route('chat.channels.mute', [$workspace, $channel]));

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channel.mutedUntil', 'forever')
            // The sidebar draws the same thing, so it has to agree.
            ->where('channels.0.mutedUntil', 'forever'));
});
