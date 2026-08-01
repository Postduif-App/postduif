<?php

use App\Enums\MemberPanelVisibility;
use App\Enums\WorkspaceRole;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The workspace with its setting on one of the three, and somebody in a role.
 *
 * @return bool Whether this member would be shown the panel.
 */
function seesMemberPanel(MemberPanelVisibility $setting, WorkspaceRole $role): bool
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
    expect(seesMemberPanel(MemberPanelVisibility::Off, WorkspaceRole::Owner))->toBeFalse();
});

it('shows it to everybody once the workspace opens it up', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Everyone, WorkspaceRole::Member))->toBeTrue();
});

it('shows it to only the people running the workspace when asked to', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Admins, WorkspaceRole::Admin))->toBeTrue()
        ->and(seesMemberPanel(MemberPanelVisibility::Admins, WorkspaceRole::Member))->toBeFalse();
});

/**
 * A guest is in the workspace for the channels they were invited to. Who else
 * is in it is not one of those, whatever the setting says.
 */
it('never shows it to a guest', function () {
    expect(seesMemberPanel(MemberPanelVisibility::Everyone, WorkspaceRole::Guest))->toBeFalse();
});
