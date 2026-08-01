<?php

use App\Enums\BroadcastMentionPolicy;
use App\Enums\WorkspaceRole;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows the general screen to whoever runs the workspace', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Owner);

    actingAs($user)
        ->get(route('workspace.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace')
            ->where('workspace.name', $workspace->name)
            ->where('workspace.slug', $workspace->slug)
        );
});

it('refuses the screen to a plain member', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, WorkspaceRole::Member);

    actingAs($user)->get(route('workspace.edit'))->assertForbidden();
});

it('only offers the workspace section to whoever runs one', function (WorkspaceRole $role, bool $shown) {
    $user = User::factory()->create();
    workspaceWithMember($user, $role);

    actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageWorkspace', $shown));
})->with([
    'eigenaar' => [WorkspaceRole::Owner, true],
    'beheerder' => [WorkspaceRole::Admin, true],
    'lid' => [WorkspaceRole::Member, false],
]);

it('tells the chat screen whether broadcasting is allowed', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);
    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canBroadcastMention', false));

    $workspace->update(['broadcast_mentions' => BroadcastMentionPolicy::Everyone]);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.canBroadcastMention', true));
});

it('lists everyone in the workspace, whoever runs it first', function () {
    $owner = User::factory()->create(['name' => 'Zoe Zwart']);
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);

    $admin = User::factory()->create(['name' => 'Bram Bakker']);
    $member = User::factory()->create(['name' => 'Anna Aalders']);
    $workspace->members()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('members', 3)
            // Sorted by standing, not alphabetically: the owner comes first
            // even though their name sorts last.
            ->where('members.0.name', 'Zoe Zwart')
            ->where('members.0.roleLabel', 'Eigenaar')
            ->where('members.1.name', 'Bram Bakker')
            ->where('members.2.name', 'Anna Aalders')
            ->where('members.2.roleLabel', 'Lid')
        );
});

it('sorts guests below ordinary members and labels them as such', function () {
    $owner = User::factory()->create(['name' => 'Zoe Zwart']);
    $workspace = workspaceWithMember($owner, WorkspaceRole::Owner);

    $guest = User::factory()->create(['name' => 'Aad Aardema']);
    $member = User::factory()->create(['name' => 'Bram Bakker']);
    $workspace->members()->attach($guest->id, ['role' => 'guest', 'joined_at' => now()]);
    $workspace->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('members', 3)
            // The guest's name sorts first alphabetically, so its position here
            // is the rank talking rather than the name.
            ->where('members.1.name', 'Bram Bakker')
            ->where('members.2.name', 'Aad Aardema')
            ->where('members.2.role', 'guest')
            ->where('members.2.roleLabel', 'Gast')
        );
});

it('carries the handle and joined date for every member', function () {
    $owner = User::factory()->create(['username' => 'zoe']);
    workspaceWithMember($owner, WorkspaceRole::Owner);

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->where('members.0.username', 'zoe')
            ->whereNot('members.0.joinedAt', null)
        );
});

it('never shows members of another workspace', function () {
    $owner = User::factory()->create();
    workspaceWithMember($owner, WorkspaceRole::Owner);

    $stranger = User::factory()->create(['name' => 'Iemand Anders']);
    workspaceWithMember($stranger, WorkspaceRole::Owner);

    actingAs($owner)
        ->get(route('workspace.members.index'))
        ->assertInertia(fn ($page) => $page
            ->has('members', 1)
            ->whereNot('members.0.name', 'Iemand Anders')
        );
});

it('renames the workspace without touching its address', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Owner);
    $slug = $workspace->slug;

    actingAs($user)
        ->patch(route('workspace.update'), [
            'name' => 'Postduif BV',
        ])
        ->assertRedirect();

    // The slug sits in every URL, so a rename must not break shared links.
    expect($workspace->fresh()->name)->toBe('Postduif BV')
        ->and($workspace->fresh()->slug)->toBe($slug);
});

it('refuses an empty or overlong name', function (string $name) {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Owner);

    actingAs($user)
        ->patch(route('workspace.update'), [
            'name' => $name,
        ])
        ->assertSessionHasErrors('name');
})->with([
    'leeg' => '',
    'te lang' => fn () => str_repeat('a', 61),
]);

it('refuses a rename from a plain member', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, WorkspaceRole::Member);

    actingAs($user)
        ->patch(route('workspace.update'), [
            'name' => 'Gekaapt',
        ])
        ->assertForbidden();

    expect($workspace->fresh()->name)->not->toBe('Gekaapt');
});
