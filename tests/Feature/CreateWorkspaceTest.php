<?php

use App\Actions\Workspace\CreateWorkspace;
use App\Enums\SystemRole;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Where workspaces come from, and where they no longer do.
 *
 * There used to be a form at /app/nieuw: anybody signed in could make one for
 * themselves. That door is closed — a workspace is something a beheerder hands
 * out from the admin panel, and nothing on the outside may make one.
 *
 * The action itself stays, because the installer still uses it to build the
 * very first workspace on a fresh platform. The tests below drive it directly
 * rather than through a request, which is now the only way it is reached.
 */
it('has no public route left for making a workspace', function () {
    expect(Route::has('workspaces.create'))->toBeFalse()
        ->and(Route::has('workspaces.store'))->toBeFalse();
});

it('refuses a request to the address the form used to live at', function () {
    // Not a 404: /app/nieuw is an ordinary workspace slug now, so the router
    // matches the chat route and turns down the method. Either way nothing is
    // made, which is the part that matters.
    actingAs(User::factory()->create())
        ->post('/app/nieuw', ['name' => 'Van Buiten'])
        ->assertMethodNotAllowed();

    expect(Workspace::count())->toBe(0);
});

/**
 * The state every new account starts in: signed in, verified, and belonging
 * nowhere. A 404 here would read as a broken account rather than as one that
 * is waiting for an invitation, so the page says which of the two it is.
 */
it('tells somebody who belongs nowhere why there is nothing here', function () {
    actingAs(User::factory()->create())
        ->get(route('chat.home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('workspaces/none'));
});

it('still sends a member straight to their workspace', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->get(route('chat.home'))
        ->assertRedirect(route('chat.index', $workspace));
});

it('draws that page rather than falling back to an empty shell', function () {
    // The page is mapped to the auth shell in app.tsx; a React error there
    // would leave SSR quietly rendering nothing and the Inertia assertion
    // above would still pass.
    skipWithoutSsr();

    $html = actingAs(User::factory()->create())
        ->get(route('chat.home'))
        ->getContent();

    expect($html)->toContain('Je hoort nog nergens bij');
});

it('makes the workspace, its owner and somewhere to talk in one go', function () {
    $user = User::factory()->create();

    $workspace = app(CreateWorkspace::class)->handle($user, 'De Vries & Zonen');

    /*
     * All three or none. A workspace nobody is a member of cannot be opened,
     * and one with no channel used to answer 404 at the door — so any two out
     * of the three is another dead end.
     */
    expect($workspace->owner_id)->toBe($user->id)
        ->and($workspace->hasMember($user))->toBeTrue()
        ->and($workspace->roleFor($user)?->key)->toBe(SystemRole::Owner->value)
        ->and($workspace->channels()->count())->toBe(1);
});

it('lands the maker inside the workspace they just made', function () {
    $user = User::factory()->create();

    $workspace = app(CreateWorkspace::class)->handle($user, 'Tweede Verdieping');

    // chat.index rather than the channel directly: it already knows how to
    // pick the one to land in, and that is one decision rather than two.
    actingAs($user)
        ->get(route('chat.index', $workspace))
        ->assertRedirect(route('chat.show', [$workspace, $workspace->channels()->sole()]));
});

it('derives a readable address from the name', function () {
    $workspace = app(CreateWorkspace::class)
        ->handle(User::factory()->create(), 'De Vries & Zonen');

    expect($workspace->slug)->toBe('de-vries-zonen');
});

it('lets two workspaces share a name without sharing an address', function () {
    Workspace::factory()->create(['slug' => 'de-bakkerij']);

    $second = app(CreateWorkspace::class)
        ->handle(User::factory()->create(), 'De Bakkerij');

    /*
     * The name goes through untouched and the address quietly gains a suffix.
     * Refusing the name would be refusing it because a stranger somewhere else
     * got there first, which is a rule nobody outside can see the reason for.
     */
    expect($second->slug)->not->toBe('de-bakkerij')
        ->and($second->slug)->toStartWith('de-bakkerij-');
});

it('never hands out an address the router has spoken for', function () {
    $workspace = app(CreateWorkspace::class)
        ->handle(User::factory()->create(), 'Settings');

    // /app/settings is the settings shell. A workspace that claimed it would
    // be a workspace nobody can open.
    expect($workspace->slug)->not->toBeIn(Workspace::RESERVED_SLUGS);
});
