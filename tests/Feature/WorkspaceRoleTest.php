<?php

use App\Enums\WorkspaceRole;

it('labels every role', function (WorkspaceRole $role, string $label) {
    expect($role->getLabel())->toBe($label);
})->with([
    [WorkspaceRole::Owner, 'Eigenaar'],
    [WorkspaceRole::Admin, 'Beheerder'],
    [WorkspaceRole::Member, 'Lid'],
    [WorkspaceRole::Guest, 'Gast'],
]);

it('keeps managing the workspace to the owner and admins', function () {
    expect(WorkspaceRole::Owner->canManageWorkspace())->toBeTrue()
        ->and(WorkspaceRole::Admin->canManageWorkspace())->toBeTrue()
        ->and(WorkspaceRole::Member->canManageWorkspace())->toBeFalse()
        ->and(WorkspaceRole::Guest->canManageWorkspace())->toBeFalse();
});

it('lets nobody but the owner and admins invite', function () {
    expect(WorkspaceRole::Guest->canInviteMembers())->toBeFalse()
        ->and(WorkspaceRole::Member->canInviteMembers())->toBeFalse()
        ->and(WorkspaceRole::Admin->canInviteMembers())->toBeTrue();
});

it('marks only the guest as a guest', function () {
    expect(WorkspaceRole::Guest->isGuest())->toBeTrue()
        ->and(WorkspaceRole::Member->isGuest())->toBeFalse()
        ->and(WorkspaceRole::Admin->isGuest())->toBeFalse()
        ->and(WorkspaceRole::Owner->isGuest())->toBeFalse();
});

/**
 * The check every visibility rule hangs on. A guest is the only role that may
 * not look around the workspace; everybody else may.
 */
it('closes the workspace to guests only', function () {
    expect(WorkspaceRole::Guest->canBrowseWorkspace())->toBeFalse()
        ->and(WorkspaceRole::Member->canBrowseWorkspace())->toBeTrue()
        ->and(WorkspaceRole::Admin->canBrowseWorkspace())->toBeTrue()
        ->and(WorkspaceRole::Owner->canBrowseWorkspace())->toBeTrue();
});

it('ranks roles by standing, guests last', function () {
    $ranked = collect(WorkspaceRole::cases())
        ->sortBy(fn (WorkspaceRole $role) => $role->rank())
        ->map(fn (WorkspaceRole $role) => $role->value)
        ->values()
        ->all();

    expect($ranked)->toBe(['owner', 'admin', 'member', 'guest']);
});
