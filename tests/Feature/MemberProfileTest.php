<?php

use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * A member and a colleague in the same workspace.
 *
 * @return array{0: User, 1: User, 2: Workspace}
 */
function profileFixture(): array
{
    $viewer = User::factory()->create();
    $workspace = workspaceWithMember($viewer);

    $colleague = User::factory()->create([
        'timezone' => 'Europe/Amsterdam',
        'bio' => 'Backend, meestal in de API. Op dinsdag vrij.',
    ]);
    $workspace->members()->attach($colleague->id, ['joined_at' => now()]);

    return [$viewer, $colleague, $workspace];
}

it('shows who somebody is', function () {
    [$viewer, $colleague, $workspace] = profileFixture();

    actingAs($viewer)
        ->get(route('chat.members.show', [$workspace, $colleague]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('chat/member')
            ->where('member.name', $colleague->name)
            ->where('member.bio', 'Backend, meestal in de API. Op dinsdag vrij.')
            ->where('member.timezone', 'Europe/Amsterdam')
            ->where('member.isYou', false)
            // Inside the chat shell, so the sidebar you came from is still there.
            ->has('channels'));
});

it('says what time it is where they are', function () {
    [$viewer, $colleague, $workspace] = profileFixture();

    $colleague->forceFill(['timezone' => 'Pacific/Auckland'])->save();

    $response = actingAs($viewer)
        ->get(route('chat.members.show', [$workspace, $colleague]))
        ->assertOk();

    /*
     * Worked out on the server, because the reader's machine knows its own
     * clock and not somebody else's zone — and the question this answers is
     * "can I message them now".
     */
    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->where('member.localTime', now('Pacific/Auckland')->format('H:i')));
});

it('knows when it is your own page', function () {
    [$viewer, , $workspace] = profileFixture();

    actingAs($viewer)
        ->get(route('chat.members.show', [$workspace, $viewer]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('member.isYou', true));
});

it('leaves the bio empty rather than blank when nobody wrote one', function () {
    [$viewer, $colleague, $workspace] = profileFixture();

    $colleague->forceFill(['bio' => null])->save();

    actingAs($viewer)
        ->get(route('chat.members.show', [$workspace, $colleague]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('member.bio', null));
});

it('has no page for somebody in another workspace', function () {
    [$viewer, , $workspace] = profileFixture();

    $stranger = User::factory()->create();
    workspaceWithMember($stranger);

    /*
     * A 404 rather than a 403: as far as this member is concerned that person
     * is not here, and saying "forbidden" would confirm they exist.
     */
    actingAs($viewer)
        ->get(route('chat.members.show', [$workspace, $stranger]))
        ->assertNotFound();
});

it('refuses somebody who is not in the workspace at all', function () {
    [, $colleague, $workspace] = profileFixture();

    $outsider = User::factory()->create();

    actingAs($outsider)
        ->get(route('chat.members.show', [$workspace, $colleague]))
        ->assertForbidden();
});

it('lets somebody write a line about themselves', function () {
    [$viewer] = profileFixture();

    actingAs($viewer)->patch(route('profile.update'), [
        'name' => $viewer->name,
        'username' => $viewer->username,
        'email' => $viewer->email,
        'timezone' => $viewer->timezone,
        'bio' => '  Frontend, en de koffie.  ',
    ])->assertRedirect();

    // Stored without the padding: Laravel's TrimStrings middleware gets to the
    // input before any of this does, which is why nothing here has to.
    expect($viewer->fresh()->bio)->toBe('Frontend, en de koffie.');
});

it('reads a bio of only spaces as none at all', function () {
    [$viewer] = profileFixture();

    $viewer->forceFill(['bio' => 'Iets'])->save();

    actingAs($viewer)->patch(route('profile.update'), [
        'name' => $viewer->name,
        'username' => $viewer->username,
        'email' => $viewer->email,
        'timezone' => $viewer->timezone,
        'bio' => '   ',
    ])->assertRedirect();

    // Null rather than a string of spaces, so "has written something" stays a
    // null check everywhere it is read.
    expect($viewer->fresh()->bio)->toBeNull();
});
