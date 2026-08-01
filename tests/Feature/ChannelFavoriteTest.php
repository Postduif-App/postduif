<?php

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A channel somebody might want at the top of their own list.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function favouritableChannel(): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    return [$user, $workspace, $channel];
}

it('puts a channel at the top for this member', function () {
    [$user, $workspace, $channel] = favouritableChannel();

    actingAs($user)
        ->post(route('chat.channels.favorite', [$workspace, $channel]))
        ->assertRedirect();

    expect($channel->fresh()->membershipFor($user)->favorited_at)->not->toBeNull();
});

it('takes it out of the favourites again', function () {
    [$user, $workspace, $channel] = favouritableChannel();

    actingAs($user)->post(route('chat.channels.favorite', [$workspace, $channel]));
    actingAs($user)->delete(route('chat.channels.unfavorite', [$workspace, $channel]))
        ->assertRedirect();

    expect($channel->fresh()->membershipFor($user)->favorited_at)->toBeNull();
});

/** How you order your work is yours; nobody else's list moves. */
it('leaves everybody else where they were', function () {
    [$user, $workspace, $channel] = favouritableChannel();

    $colleague = User::factory()->create();
    $workspace->members()->attach($colleague->id, ['role' => 'member', 'joined_at' => now()]);
    $channel->members()->attach($colleague->id, ['joined_at' => now()]);

    actingAs($user)->post(route('chat.channels.favorite', [$workspace, $channel]));

    actingAs($colleague)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channel.isFavorite', false));

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channel.isFavorite', true)
            // And the sidebar says the same thing.
            ->where('channels', fn ($rows) => collect($rows)
                ->contains(fn (array $row): bool => $row['id'] === $channel->id
                    && $row['isFavorite'] === true)));
});

it('refuses somebody who is not in the channel', function () {
    [, $workspace, $channel] = favouritableChannel();

    $outsider = User::factory()->create();
    $workspace->members()->attach($outsider->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($outsider)
        ->post(route('chat.channels.favorite', [$workspace, $channel]))
        ->assertForbidden();
});

/** Two different decisions about your own attention; neither cancels the other. */
it('lets a channel be both muted and a favourite', function () {
    [$user, $workspace, $channel] = favouritableChannel();

    actingAs($user)->post(route('chat.channels.favorite', [$workspace, $channel]));
    actingAs($user)->post(route('chat.channels.mute', [$workspace, $channel]));

    $membership = $channel->fresh()->membershipFor($user);

    expect($membership->favorited_at)->not->toBeNull()
        ->and($membership->isMuted())->toBeTrue();
});
