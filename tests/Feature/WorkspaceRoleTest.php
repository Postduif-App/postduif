<?php

/*
 * In Feature rather than Unit since the enum labels became translations: a
 * label now goes through the translator, and the translator needs a booted
 * application. The rest of what these check is still pure — the move costs a
 * little speed and buys the assertion being able to run at all.
 */

use App\Enums\SystemRole;

it('labels every role', function (SystemRole $role, string $label) {
    expect($role->getLabel())->toBe($label);
})->with([
    [SystemRole::Owner, 'Eigenaar'],
    [SystemRole::Admin, 'Beheerder'],
    [SystemRole::Member, 'Lid'],
    [SystemRole::Guest, 'Gast'],
]);

it('keeps managing the workspace to the owner and admins', function () {
    expect(SystemRole::Owner->canManageWorkspace())->toBeTrue()
        ->and(SystemRole::Admin->canManageWorkspace())->toBeTrue()
        ->and(SystemRole::Member->canManageWorkspace())->toBeFalse()
        ->and(SystemRole::Guest->canManageWorkspace())->toBeFalse();
});

it('lets nobody but the owner and admins invite', function () {
    expect(SystemRole::Guest->canInviteMembers())->toBeFalse()
        ->and(SystemRole::Member->canInviteMembers())->toBeFalse()
        ->and(SystemRole::Admin->canInviteMembers())->toBeTrue();
});

it('marks only the guest as a guest', function () {
    expect(SystemRole::Guest->isGuest())->toBeTrue()
        ->and(SystemRole::Member->isGuest())->toBeFalse()
        ->and(SystemRole::Admin->isGuest())->toBeFalse()
        ->and(SystemRole::Owner->isGuest())->toBeFalse();
});

/**
 * The check every visibility rule hangs on. A guest is the only role that may
 * not look around the workspace; everybody else may.
 */
it('closes the workspace to guests only', function () {
    expect(SystemRole::Guest->canBrowseWorkspace())->toBeFalse()
        ->and(SystemRole::Member->canBrowseWorkspace())->toBeTrue()
        ->and(SystemRole::Admin->canBrowseWorkspace())->toBeTrue()
        ->and(SystemRole::Owner->canBrowseWorkspace())->toBeTrue();
});

it('ranks roles by standing, guests last', function () {
    $ranked = collect(SystemRole::cases())
        ->sortBy(fn (SystemRole $role) => $role->rank())
        ->map(fn (SystemRole $role) => $role->value)
        ->values()
        ->all();

    expect($ranked)->toBe(['owner', 'admin', 'member', 'guest']);
});
