<?php

use App\Enums\ChannelCreationPolicy;
use App\Enums\WorkspaceRole;
use App\Models\Channel;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lets anybody who belongs here open a channel by default', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);

    expect($workspace->channel_creation)->toBe(ChannelCreationPolicy::Everyone);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
        ])
        ->assertRedirect();

    expect(Channel::firstWhere('slug', 'marketing'))->not->toBeNull();
});

it('closes channel creation to plain members once the workspace says so', function (WorkspaceRole $role, bool $allowed) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);
    $workspace->update(['channel_creation' => ChannelCreationPolicy::Admins]);

    $response = actingAs($user)->post(route('chat.channels.store', $workspace), [
        'name' => 'marketing',
        'type' => 'public',
    ]);

    $allowed ? $response->assertRedirect() : $response->assertForbidden();

    expect(Channel::firstWhere('slug', 'marketing') !== null)->toBe($allowed);
})->with([
    'eigenaar' => [WorkspaceRole::Owner, true],
    'beheerder' => [WorkspaceRole::Admin, true],
    'lid' => [WorkspaceRole::Member, false],
    'gast' => [WorkspaceRole::Guest, false],
]);

it('keeps a guest out even when everybody else may', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Guest);
    $workspace->update(['channel_creation' => ChannelCreationPolicy::Everyone]);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
        ])
        ->assertForbidden();
});

it('stops offering the button to whoever may no longer use it', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canCreateChannel', true));

    $workspace->update(['channel_creation' => ChannelCreationPolicy::Admins]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canCreateChannel', false));
});
