<?php

use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Models\Channel;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('lets anybody who belongs here open a channel by default', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Member);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
        ])
        ->assertRedirect();

    expect(Channel::firstWhere('slug', 'marketing'))->not->toBeNull();
});

it('closes channel creation to plain members once the workspace says so', function (SystemRole $role, bool $allowed) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);
    setAbility($workspace, WorkspaceAbility::CreateChannels, false, SystemRole::Member);

    $response = actingAs($user)->post(route('chat.channels.store', $workspace), [
        'name' => 'marketing',
        'type' => 'public',
    ]);

    $allowed ? $response->assertRedirect() : $response->assertForbidden();

    expect(Channel::firstWhere('slug', 'marketing') !== null)->toBe($allowed);
})->with([
    'eigenaar' => [SystemRole::Owner, true],
    'beheerder' => [SystemRole::Admin, true],
    'lid' => [SystemRole::Member, false],
    'gast' => [SystemRole::Guest, false],
]);

it('keeps a guest out even when everybody else may', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Guest);
    setAbility($workspace, WorkspaceAbility::CreateChannels, true, SystemRole::Member);

    actingAs($user)
        ->post(route('chat.channels.store', $workspace), [
            'name' => 'marketing',
            'type' => 'public',
        ])
        ->assertForbidden();
});

it('stops offering the button to whoever may no longer use it', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Member);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canCreateChannel', true));

    setAbility($workspace, WorkspaceAbility::CreateChannels, false, SystemRole::Member);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canCreateChannel', false));
});
