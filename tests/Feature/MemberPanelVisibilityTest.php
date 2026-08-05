<?php

use App\Enums\MemberPanelVisibility;
use App\Enums\SystemRole;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The workspace with its setting on one of the three, and somebody in a role.
 *
 * @return bool Whether this member would be shown the panel.
 */
function seesMemberPanel(MemberPanelVisibility $setting, SystemRole $role): bool
{
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, $role);
    $workspace->update(['member_panel' => $setting]);

    $channel = channelWithMember($workspace, $user);

    $seen = null;

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(function ($page) use (&$seen) {
            $seen = $page->toArray()['props']['workspace']['showsMemberPanel'];
        });

    return (bool) $seen;
}

it('keeps the panel to itself until a workspace asks for it', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Off, SystemRole::Owner))->toBeFalse();
});

it('shows it to everybody once the workspace opens it up', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Everyone, SystemRole::Member))->toBeTrue();
});

it('shows it to only the people running the workspace when asked to', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Admins, SystemRole::Admin))->toBeTrue()
        ->and(seesMemberPanel(MemberPanelVisibility::Admins, SystemRole::Member))->toBeFalse();
});

/**
 * A guest is in the workspace for the channels they were invited to. Who else
 * is in it is not one of those, whatever the setting says.
 */
it('never shows it to a guest', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Everyone, SystemRole::Guest))->toBeFalse();
});

it('sends the people in the workspace along with the panel', function () {
    $user = User::factory()->create(['name' => 'Anna Bakker']);
    $workspace = workspaceWithMember($user, SystemRole::Owner);
    $workspace->update(['member_panel' => MemberPanelVisibility::Everyone]);

    $colleague = User::factory()->create(['name' => 'Bram de Vries']);
    joinWorkspace($workspace, $colleague, SystemRole::Member);

    $guest = User::factory()->create(['name' => 'Carla Klant']);
    joinWorkspace($workspace, $guest, SystemRole::Guest);

    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->has('workspaceMembers', 2)
            ->where('workspaceMembers.0.name', 'Anna Bakker')
            ->where('workspaceMembers.1.name', 'Bram de Vries')
        );
});

/**
 * Not merely hidden by the browser: a member who does not get the panel is not
 * sent the list either.
 */
it('sends nobody to a member who does not get the panel', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Member);
    $workspace->update(['member_panel' => MemberPanelVisibility::Admins]);

    $channel = channelWithMember($workspace, $user);

    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->has('workspaceMembers', 0));
});

it('remembers that the panel was left open', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);
    $workspace->update(['member_panel' => MemberPanelVisibility::Everyone]);

    $channel = channelWithMember($workspace, $user);

    // Nothing said yet: the extra panel starts out of the way.
    actingAs($user)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('memberPanelOpen', false));

    actingAs($user)
        ->withUnencryptedCookie('member_panel_state', 'true')
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('memberPanelOpen', true));
});
