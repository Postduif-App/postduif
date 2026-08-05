<?php

use App\Enums\ChannelPostingPolicy;
use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * A channel only admins may post in, with one ordinary member in it.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function broadcastChannel(): array
{
    $creator = User::factory()->create();
    $workspace = workspaceWithMember($creator);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $creator->id,
        'posting_policy' => ChannelPostingPolicy::Admins,
    ]);
    $channel->members()->attach($creator->id, ['joined_at' => now()]);

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    return [$member, $workspace, $channel];
}

function postMessage(User $user, Workspace $workspace, Channel $channel, ?string $parentId = null)
{
    return actingAs($user)->post(route('chat.messages.store', [$workspace, $channel]), [
        'id' => Str::lower((string) Str::ulid()),
        'body' => 'Iets te zeggen',
        'parent_id' => $parentId,
    ]);
}

it('opens a channel to every member by default', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);
    $channel = channelWithMember($workspace, $user);

    expect($channel->posting_policy)->toBe(ChannelPostingPolicy::Everyone);

    postMessage($user, $workspace, $channel)->assertRedirect();
});

it('refuses a message from an ordinary member in a broadcast channel', function () {
    [$member, $workspace, $channel] = broadcastChannel();

    postMessage($member, $workspace, $channel)->assertForbidden();

    expect($channel->messages()->count())->toBe(0);
});

it('lets the channel creator post in their own broadcast channel', function () {
    [, $workspace, $channel] = broadcastChannel();

    $creator = User::find($channel->created_by);

    postMessage($creator, $workspace, $channel)->assertRedirect();
});

it('lets a workspace admin post in a broadcast channel', function () {
    [$member, $workspace, $channel] = broadcastChannel();

    $workspace->members()->updateExistingPivot($member->id, [
        'role' => SystemRole::Admin->value,
    ]);

    postMessage($member, $workspace, $channel)->assertRedirect();
});

it('still lets an ordinary member answer in a thread', function () {
    [$member, $workspace, $channel] = broadcastChannel();

    $parent = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $channel->created_by,
    ]);

    // The whole point of the distinction: an announcement has to stay
    // answerable, or the channel is a noticeboard.
    postMessage($member, $workspace, $channel, $parent->id)->assertRedirect();

    expect($parent->replies()->count())->toBe(1);
});

it('still lets an ordinary member react in a broadcast channel', function () {
    [$member, $workspace, $channel] = broadcastChannel();

    $message = Message::factory()->create([
        'channel_id' => $channel->id,
        'workspace_id' => $workspace->id,
        'user_id' => $channel->created_by,
    ]);

    actingAs($member)
        ->post(route('chat.messages.reactions.store', [$workspace, $channel, $message]), [
            'emoji' => '👍',
        ])
        ->assertRedirect();

    expect($message->reactions()->count())->toBe(1);
});

it('refuses everyone once the channel is archived', function () {
    [, $workspace, $channel] = broadcastChannel();

    $creator = User::find($channel->created_by);
    $channel->forceFill(['archived_at' => now()])->save();

    postMessage($creator, $workspace, $channel)->assertForbidden();
});

it('tells the page whether this member may post', function () {
    [$member, $workspace, $channel] = broadcastChannel();

    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('channel.canPost', false)
            ->where('channel.postingPolicy', 'admins')
        );

    actingAs(User::find($channel->created_by))
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('channel.canPost', true));
});
