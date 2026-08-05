<?php

use App\Enums\SystemRole;
use App\Features\Transfers;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;

/**
 * The two rules the permissions endpoint has always required. Sent along with
 * every request here because leaving them out is a validation failure about
 * something else entirely.
 *
 * @return array<string, string>
 */
function standingRules(): array
{
    return [
    ];
}

it('does not send files out of a workspace that never said it could', function () {
    expect(Workspace::factory()->create()->hasFeature(Transfers::class))->toBeFalse();
});

it('gives a new workspace ceilings to work within from the start', function () {
    $workspace = Workspace::factory()->create();

    // Read off the unsaved model too: a database default only applies on
    // insert, and a ceiling that reads null until a refresh is a ceiling that
    // is not there when the first upload is measured against it.
    expect(new Workspace)
        ->max_transfer_kb->toBe(2097152)
        ->max_transfer_days->toBe(14);

    expect($workspace->refresh())
        ->max_transfer_kb->toBe(2097152)
        ->max_transfer_days->toBe(14);
});

it('keeps the ceilings off the screen while the feature is off', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->get(route('workspace.permissions.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('workspace.transfersEnabled', false));
});

it('shows the ceilings once the workspace has the feature', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);

    Feature::for($workspace)->activate(Transfers::class);

    actingAs($user)
        ->get(route('workspace.permissions.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('workspace.transfersEnabled', true)
            ->where('workspace.maxTransferKb', 2097152)
            ->where('workspace.maxTransferDays', 14)
        );
});

/** Asked in megabytes, because that is what somebody setting a limit thinks in. */
it('takes the size ceiling in megabytes and stores kilobytes', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Admin);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            ...standingRules(),
            'max_transfer_mb' => 500,
            'max_transfer_days' => 7,
        ])
        ->assertRedirect();

    expect($workspace->fresh())
        ->max_transfer_kb->toBe(500 * 1024)
        ->max_transfer_days->toBe(7);
});

it('refuses a ceiling nobody could wait out', function (array $payload) {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Owner);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [...standingRules(), ...$payload])
        ->assertSessionHasErrors(array_key_first($payload));
})->with([
    'more than ten gigabytes' => [['max_transfer_mb' => 20480]],
    'nothing at all' => [['max_transfer_mb' => 0]],
    'longer than a quarter' => [['max_transfer_days' => 365]],
    'no days at all' => [['max_transfer_days' => 0]],
]);

/**
 * The endpoint served the older rules long before this feature existed, so a
 * request that says nothing about transfers leaves them as they were — the same
 * reasoning the attachment settings are validated with.
 */
it('leaves the ceilings alone when a request says nothing about them', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user, SystemRole::Owner);
    $workspace->update(['max_transfer_kb' => 51200, 'max_transfer_days' => 3]);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), standingRules())
        ->assertRedirect();

    expect($workspace->fresh())
        ->max_transfer_kb->toBe(51200)
        ->max_transfer_days->toBe(3);
});

it('refuses the whole screen to a plain member, ceilings and all', function () {
    $user = User::factory()->create();
    workspaceWithMember($user, SystemRole::Member);

    actingAs($user)
        ->patch(route('workspace.permissions.update'), [
            ...standingRules(),
            'max_transfer_days' => 90,
        ])
        ->assertForbidden();
});
