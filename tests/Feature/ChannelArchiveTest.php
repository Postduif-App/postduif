<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A channel with its maker still in it, plus a second one so the redirect after
 * archiving has somewhere to land.
 *
 * @return array{0: User, 1: Workspace, 2: Channel}
 */
function archivableChannel(): array
{
    $creator = User::factory()->create();
    $workspace = workspaceWithMember($creator);

    $channel = Channel::factory()->create([
        'workspace_id' => $workspace->id,
        'created_by' => $creator->id,
    ]);
    $channel->members()->attach($creator->id, ['joined_at' => now()]);

    channelWithMember($workspace, $creator);

    return [$creator, $workspace, $channel];
}

it('puts a channel away', function () {
    [$creator, $workspace, $channel] = archivableChannel();

    actingAs($creator)
        ->post(route('chat.channels.archive', [$workspace, $channel]))
        ->assertRedirect(route('chat.index', $workspace));

    expect($channel->fresh()->archived_at)->not->toBeNull();
});

it('takes it back out again', function () {
    [$creator, $workspace, $channel] = archivableChannel();

    $channel->forceFill(['archived_at' => now()])->save();

    actingAs($creator)
        ->post(route('chat.channels.archive', [$workspace, $channel]))
        ->assertRedirect();

    expect($channel->fresh()->archived_at)->toBeNull();
});

/**
 * The point of the whole feature: manageSettings refuses on an archived
 * channel, so reopening needs an ability of its own or the door locks behind
 * you.
 */
it('still lets the maker reopen a channel they can no longer configure', function () {
    [$creator, , $channel] = archivableChannel();

    $channel->forceFill(['archived_at' => now()])->save();

    expect($creator->can('manageSettings', $channel->fresh()))->toBeFalse()
        ->and($creator->can('archiveChannel', $channel->fresh()))->toBeTrue();
});

it('refuses an ordinary member', function () {
    [, $workspace, $channel] = archivableChannel();

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    actingAs($member)
        ->post(route('chat.channels.archive', [$workspace, $channel]))
        ->assertForbidden();

    expect($channel->fresh()->archived_at)->toBeNull();
});

it('lists what was put away, for whoever may take it back out', function () {
    [$creator, $workspace, $channel] = archivableChannel();

    actingAs($creator)->post(route('chat.channels.archive', [$workspace, $channel]));

    actingAs($creator)
        ->get(route('chat.index', $workspace))
        ->assertRedirect();

    actingAs($creator)
        ->get(route('chat.mentions.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('archivedChannels', 1)
            ->where('archivedChannels.0.id', $channel->id)
            // And it is gone from the live list.
            ->where('channels', fn ($rows) => collect($rows)
                ->doesntContain(fn (array $row): bool => $row['id'] === $channel->id)));
});

it('does not list it for somebody who could not reopen it', function () {
    [$creator, $workspace, $channel] = archivableChannel();

    actingAs($creator)->post(route('chat.channels.archive', [$workspace, $channel]));

    $member = User::factory()->create();
    $workspace->members()->attach($member->id, [
        'role' => SystemRole::Member->value,
        'joined_at' => now(),
    ]);

    actingAs($member)
        ->get(route('chat.mentions.index', $workspace))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('archivedChannels', 0));
});
