<?php

use App\Enums\WorkspaceRole;
use App\Features\InviteLinks;
use App\Features\MessageForwarding;
use App\Features\SavedMessages;
use App\Features\ScheduledMessages;
use App\Features\Tickets;
use App\Features\Webhooks;
use App\Features\WorkspaceFeature;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * A workspace with one feature switched off, and somebody who would otherwise
 * be allowed everything.
 *
 * @param  class-string<WorkspaceFeature>  $feature
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function workspaceWithout(string $feature): array
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Owner);
    $channel = channelWithMember($workspace, $user);

    Feature::for($workspace)->deactivate($feature);

    return [$user, $workspace, $channel];
}

it('does not schedule a message where scheduling is switched off', function () {
    [$user, $workspace, $channel] = workspaceWithout(ScheduledMessages::class);

    actingAs($user)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Toch nog even proberen',
        'send_at' => now()->addDay()->toDateTimeString(),
    ])->assertNotFound();
});

it('does not forward a message where forwarding is switched off', function () {
    [$user, $workspace, $channel] = workspaceWithout(MessageForwarding::class);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)->post(route('chat.messages.forward', [$workspace, $channel, $message]), [
        'channel_id' => $channel->id,
    ])->assertNotFound();
});

it('does not open a ticket where tickets are switched off', function () {
    [$user, $workspace, $channel] = workspaceWithout(Tickets::class);

    actingAs($user)->post(route('chat.tickets.store', [$workspace, $channel]), [
        'title' => 'Deur klemt',
    ])->assertNotFound();

    // Not the overview either: half a feature is worse than none.
    actingAs($user)->get(route('chat.tickets.index', $workspace))->assertNotFound();
});

it('does not manage webhooks where webhooks are switched off', function () {
    [$user, $workspace, $channel] = workspaceWithout(Webhooks::class);

    actingAs($user)
        ->get(route('chat.channels.webhooks.index', [$workspace, $channel]))
        ->assertNotFound();
});

it('does not hand out an invite link where links are switched off', function () {
    [$user, $workspace] = workspaceWithout(InviteLinks::class);

    actingAs($user)->post(route('chat.invite-links.store', $workspace), [
        'role' => 'member',
    ])->assertNotFound();
});

it('does not save a message where saving is switched off', function () {
    [$user, $workspace, $channel] = workspaceWithout(SavedMessages::class);

    $message = Message::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => $channel->id,
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->post(route('chat.messages.bookmark', [$workspace, $channel, $message]))
        ->assertNotFound();

    actingAs($user)->get(route('chat.saved.index', $workspace))->assertNotFound();
});

/**
 * The point of a 404 rather than a 403: what a member may do is a different
 * question from what this workspace offers, and the second one must not be
 * answered in terms of the first.
 */
it('leaves everything alone in a workspace that switched nothing off', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Owner);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)->post(route('chat.channels.scheduled.store', [$workspace, $channel]), [
        'body' => 'Gewoon inplannen',
        'send_at' => now()->addDay()->toDateTimeString(),
    ])->assertRedirect();
});
