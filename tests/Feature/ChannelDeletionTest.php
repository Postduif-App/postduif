<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workspace;

use function Pest\Laravel\actingAs;

/**
 * A channel with somebody in it, plus a second one for the redirect to land on:
 * chat.index refuses a workspace with nothing visible left.
 *
 * @return array{0: User, 1: User, 2: Workspace, 3: Channel}
 */
function channelDeletionFixture(): array
{
    $creator = User::factory()->create();
    $workspace = workspaceWithMember($creator);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $creator->id,
    ]);
    $channel->members()->attach($creator->id, ['joined_at' => now()]);

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    channelWithMember($workspace, $creator);

    return [$creator, $member, $workspace, $channel];
}

it('lets the channel creator delete the channel', function () {
    [$creator, , $workspace, $channel] = channelDeletionFixture();

    actingAs($creator)
        ->delete(route('chat.channels.destroy', [$workspace, $channel]))
        ->assertRedirect(route('chat.index', $workspace));

    expect(Channel::whereKey($channel->id)->exists())->toBeFalse();
});

it('takes everything in the channel with it', function () {
    [$creator, , $workspace, $channel] = channelDeletionFixture();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'user_id' => $creator->id,
    ]);
    $ticket = Ticket::factory()->create([
        'channel_id' => $channel->id,
        'opened_by' => $creator->id,
    ]);

    actingAs($creator)->delete(route('chat.channels.destroy', [$workspace, $channel]));

    expect(Message::whereKey($message->id)->exists())->toBeFalse()
        ->and(Ticket::whereKey($ticket->id)->exists())->toBeFalse()
        ->and($channel->members()->count())->toBe(0);
});

it('refuses an ordinary member of the channel', function () {
    [, $member, $workspace, $channel] = channelDeletionFixture();

    actingAs($member)
        ->delete(route('chat.channels.destroy', [$workspace, $channel]))
        ->assertForbidden();

    expect(Channel::whereKey($channel->id)->exists())->toBeTrue();
});

it('lets whoever runs the workspace delete a channel they never joined', function () {
    [, , $workspace, $channel] = channelDeletionFixture();

    $owner = User::factory()->create();
    joinWorkspace($workspace, $owner, SystemRole::Owner);

    actingAs($owner)
        ->delete(route('chat.channels.destroy', [$workspace, $channel]))
        ->assertRedirect();

    expect(Channel::whereKey($channel->id)->exists())->toBeFalse();
});

it('still deletes an archived channel', function () {
    [$creator, , $workspace, $channel] = channelDeletionFixture();
    $channel->update(['archived_at' => now()]);

    actingAs($creator)
        ->delete(route('chat.channels.destroy', [$workspace, $channel]))
        ->assertRedirect();

    expect(Channel::whereKey($channel->id)->exists())->toBeFalse();
});

it('does not delete a direct message', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $other = User::factory()->create();
    joinWorkspace($workspace, $other, SystemRole::Member);

    $direct = Channel::factory()->direct()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $user->id,
    ]);
    $direct->members()->attach([$user->id, $other->id], ['joined_at' => now()]);

    actingAs($user)
        ->delete(route('chat.channels.destroy', [$workspace, $direct]))
        ->assertForbidden();

    expect(Channel::whereKey($direct->id)->exists())->toBeTrue();
});

it('does not reach a channel in another workspace', function () {
    [$creator, , , $channel] = channelDeletionFixture();
    $elsewhere = workspaceWithMember($creator);

    actingAs($creator)
        ->delete(route('chat.channels.destroy', [$elsewhere, $channel]))
        ->assertNotFound();

    expect(Channel::whereKey($channel->id)->exists())->toBeTrue();
});
