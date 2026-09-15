<?php

use App\Enums\SystemRole;
use App\Models\Channel;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLink;

use function Pest\Laravel\actingAs;

/**
 * A workspace, somebody who runs it, an ordinary member, a guest, and a channel
 * they can all see — so the payload assertions have somewhere to be made.
 *
 * The beheerder is an Admin rather than a Member, which settingsFixture would
 * have given: every write here goes through ResolvesCurrentWorkspace, and that
 * asks the manage ability before anything else runs.
 *
 * @return array{0: User, 1: User, 2: User, 3: Workspace, 4: Channel}
 */
function workspaceLinkFixture(): array
{
    $admin = User::factory()->create();
    $workspace = workspaceWithMember($admin, SystemRole::Admin);

    $channel = channelWithMember($workspace, $admin);

    $member = User::factory()->create();
    joinWorkspace($workspace, $member, SystemRole::Member);
    $channel->members()->attach($member->id, ['joined_at' => now()]);

    $guest = User::factory()->create();
    joinWorkspace($workspace, $guest, SystemRole::Guest);
    $channel->members()->attach($guest->id, ['joined_at' => now()]);

    return [$admin, $member, $guest, $workspace, $channel];
}

function roleIn(Workspace $workspace, SystemRole $role): Role
{
    return $workspace->roles()->where('key', $role->value)->sole();
}

it('adds a button and puts it at the end', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();
    WorkspaceLink::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);

    actingAs($admin)->post(route('workspace.links.store'), [
        'label' => 'Urenportaal',
        'url' => 'https://example.com/uren',
        'emoji' => '⏰',
        'role_ids' => [roleIn($workspace, SystemRole::Member)->id],
    ])->assertRedirect();

    $added = $workspace->links()->where('label', 'Urenportaal')->sole();

    expect($added->url)->toBe('https://example.com/uren')
        ->and($added->emoji)->toBe('⏰')
        ->and($added->position)->toBe(1)
        ->and($added->roles->pluck('key')->all())->toBe([SystemRole::Member->value]);
});

it('refuses an address that is not a web address', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    /*
     * The reason this rule exists. The button is drawn in the menu of everybody
     * the roles name, so a scheme that executes would be a beheerder handing
     * themselves something that runs in each of those browsers.
     */
    foreach (['javascript:alert(1)', 'data:text/html,<script>alert(1)</script>'] as $url) {
        actingAs($admin)->post(route('workspace.links.store'), [
            'label' => 'Stiekem',
            'url' => $url,
            'role_ids' => [roleIn($workspace, SystemRole::Member)->id],
        ])->assertSessionHasErrors('url');
    }

    expect($workspace->links()->count())->toBe(0);
});

it('keeps a button away from everybody but the roles it names', function () {
    [$admin, $member, $guest, $workspace, $channel] = workspaceLinkFixture();

    $link = WorkspaceLink::factory()->create([
        'workspace_id' => $workspace->id,
        'label' => 'Intern wiki',
    ]);
    $link->roles()->sync([roleIn($workspace, SystemRole::Member)->id]);

    // The member it was meant for.
    actingAs($member)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page
            ->where('workspace.links.0.label', 'Intern wiki')
            ->count('workspace.links', 1));

    // The guest, who is the whole reason this setting exists.
    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->count('workspace.links', 0));

    // And the beheerder, who is an Admin rather than a Member — a role that
    // was not ticked is not shown it either, however senior. The person who
    // made the button is not automatically in its audience.
    actingAs($admin)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->count('workspace.links', 0));
});

it('shows a button to a guest when the guest role is ticked', function () {
    [, , $guest, $workspace, $channel] = workspaceLinkFixture();

    $link = WorkspaceLink::factory()->create([
        'workspace_id' => $workspace->id,
        'label' => 'Klantportaal',
    ]);
    $link->roles()->sync([roleIn($workspace, SystemRole::Guest)->id]);

    actingAs($guest)
        ->get(route('chat.show', [$workspace, $channel]))
        ->assertInertia(fn ($page) => $page->where('workspace.links.0.label', 'Klantportaal'));
});

it('shows a button to nobody while it names no role', function () {
    [$admin, $member, $guest, $workspace, $channel] = workspaceLinkFixture();

    WorkspaceLink::factory()->create(['workspace_id' => $workspace->id]);

    foreach ([$admin, $member, $guest] as $reader) {
        actingAs($reader)
            ->get(route('chat.show', [$workspace, $channel]))
            ->assertInertia(fn ($page) => $page->count('workspace.links', 0));
    }
});

it('refuses a role belonging to another workspace', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    $elsewhere = Workspace::factory()->create();

    actingAs($admin)->post(route('workspace.links.store'), [
        'label' => 'Vreemd',
        'url' => 'https://example.com',
        'role_ids' => [roleIn($elsewhere, SystemRole::Member)->id],
    ])->assertSessionHasErrors('role_ids.0');

    expect($workspace->links()->count())->toBe(0);
});

it('changes a button and the roles it is drawn for', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    $link = WorkspaceLink::factory()->create(['workspace_id' => $workspace->id]);
    $link->roles()->sync([roleIn($workspace, SystemRole::Member)->id]);

    actingAs($admin)->patch(route('workspace.links.update', $link), [
        'label' => 'Nieuwe naam',
        'url' => 'https://example.com/anders',
        'emoji' => null,
        'role_ids' => [roleIn($workspace, SystemRole::Guest)->id],
    ])->assertRedirect();

    $link->refresh()->load('roles');

    expect($link->label)->toBe('Nieuwe naam')
        ->and($link->url)->toBe('https://example.com/anders')
        ->and($link->roles->pluck('key')->all())->toBe([SystemRole::Guest->value]);
});

it('lets a button go quiet when every role is unticked', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    $link = WorkspaceLink::factory()->forEveryone()->create(['workspace_id' => $workspace->id]);

    actingAs($admin)->patch(route('workspace.links.update', $link), [
        'label' => $link->label,
        'url' => $link->url,
        'role_ids' => [],
    ])->assertRedirect();

    expect($link->fresh()->roles()->count())->toBe(0);
});

it('removes a button', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    $link = WorkspaceLink::factory()->forEveryone()->create(['workspace_id' => $workspace->id]);

    actingAs($admin)->delete(route('workspace.links.destroy', $link))->assertRedirect();

    expect($workspace->links()->count())->toBe(0);
});

/*
 * A role can only be deleted once nobody holds it — see WorkspaceRoleController
 * — so this is the tidying up that has to happen by itself. The link survives
 * its audience; it just stops having one.
 */
it('drops the pivot row when a role is deleted and leaves the button standing', function () {
    [, , , $workspace] = workspaceLinkFixture();

    $role = $workspace->roles()->create([
        'key' => 'leverancier',
        'name' => 'Leverancier',
        'is_external' => true,
        'position' => 10,
        'abilities' => [],
    ]);
    $link = WorkspaceLink::factory()->create(['workspace_id' => $workspace->id]);
    $link->roles()->sync([$role->id]);

    $role->delete();

    expect($link->fresh())->not->toBeNull()
        ->and($link->fresh()->roles()->count())->toBe(0);
});

it('puts the buttons in the order it is given', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    $first = WorkspaceLink::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
    $second = WorkspaceLink::factory()->create(['workspace_id' => $workspace->id, 'position' => 1]);

    actingAs($admin)->put(route('workspace.links.reorder'), [
        'ids' => [$second->id, $first->id],
    ])->assertRedirect();

    expect($workspace->links()->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('ignores ids from another workspace while reordering', function () {
    [$admin, , , $workspace] = workspaceLinkFixture();

    $ours = WorkspaceLink::factory()->create(['workspace_id' => $workspace->id, 'position' => 0]);
    $theirs = WorkspaceLink::factory()->create(['position' => 0]);

    actingAs($admin)->put(route('workspace.links.reorder'), [
        'ids' => [$theirs->id, $ours->id],
    ])->assertRedirect();

    expect($ours->fresh()->position)->toBe(1)
        ->and($theirs->fresh()->position)->toBe(0);
});

it('refuses everybody who does not run the workspace', function () {
    [, $member, $guest, $workspace] = workspaceLinkFixture();

    $link = WorkspaceLink::factory()->create(['workspace_id' => $workspace->id]);

    foreach ([$member, $guest] as $reader) {
        actingAs($reader)->get(route('workspace.links.index'))->assertForbidden();

        actingAs($reader)->post(route('workspace.links.store'), [
            'label' => 'Van mij',
            'url' => 'https://example.com',
            'role_ids' => [],
        ])->assertForbidden();

        actingAs($reader)->patch(route('workspace.links.update', $link), [
            'label' => 'Gekaapt',
            'url' => 'https://example.com',
            'role_ids' => [],
        ])->assertForbidden();

        actingAs($reader)->delete(route('workspace.links.destroy', $link))->assertForbidden();
    }

    expect($workspace->links()->count())->toBe(1)
        ->and($link->fresh()->label)->not->toBe('Gekaapt');
});

it('answers 404 for a link belonging to another workspace', function () {
    [$admin] = workspaceLinkFixture();

    $theirs = WorkspaceLink::factory()->create();

    actingAs($admin)->delete(route('workspace.links.destroy', $theirs))->assertNotFound();

    expect($theirs->fresh())->not->toBeNull();
});
