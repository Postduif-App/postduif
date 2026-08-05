<?php

use App\Enums\SystemRole;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

/**
 * Making a workspace of your own.
 *
 * The state this exists for is the one every new account starts in: signed in,
 * verified, and belonging nowhere. Before this, /app looked for a workspace,
 * found none and answered 404 — an account you cannot do anything with, handed
 * over one form after somebody asked for it.
 */
it('sends somebody who belongs nowhere to make one', function () {
    actingAs(User::factory()->create())
        ->get(route('chat.home'))
        ->assertRedirect(route('workspaces.create'));
});

it('still sends a member straight to their workspace', function () {
    $user = User::factory()->create();
    $workspace = workspaceWithMember($user);

    actingAs($user)
        ->get(route('chat.home'))
        ->assertRedirect(route('chat.index', $workspace));
});

it('says why somebody is here when they have nothing yet', function () {
    actingAs(User::factory()->create())
        ->get(route('workspaces.create'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('workspaces/create')
            ->where('isFirst', true));
});

it('knows the difference when they already have one', function () {
    $user = User::factory()->create();
    workspaceWithMember($user);

    actingAs($user)
        ->get(route('workspaces.create'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('isFirst', false));
});

it('makes the workspace, its owner and somewhere to talk in one go', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'De Vries & Zonen'])
        ->assertRedirect();

    $workspace = Workspace::where('name', 'De Vries & Zonen')->sole();

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

    actingAs($user)->post(route('workspaces.store'), ['name' => 'Tweede Verdieping']);

    $workspace = Workspace::where('name', 'Tweede Verdieping')->sole();

    // chat.index rather than the channel directly: it already knows how to
    // pick the one to land in, and that is one decision rather than two.
    actingAs($user)
        ->get(route('chat.index', $workspace))
        ->assertRedirect(route('chat.show', [$workspace, $workspace->channels()->sole()]));
});

it('derives a readable address from the name', function () {
    actingAs(User::factory()->create())
        ->post(route('workspaces.store'), ['name' => 'De Vries & Zonen']);

    expect(Workspace::where('name', 'De Vries & Zonen')->sole()->slug)
        ->toBe('de-vries-zonen');
});

it('lets two workspaces share a name without sharing an address', function () {
    Workspace::factory()->create(['slug' => 'de-bakkerij']);

    actingAs(User::factory()->create())
        ->post(route('workspaces.store'), ['name' => 'De Bakkerij']);

    $second = Workspace::where('name', 'De Bakkerij')->sole();

    /*
     * The name goes through untouched and the address quietly gains a suffix.
     * Refusing the name would be refusing it because a stranger somewhere else
     * got there first, which is a rule nobody outside can see the reason for.
     */
    expect($second->slug)->not->toBe('de-bakkerij')
        ->and($second->slug)->toStartWith('de-bakkerij-');
});

it('never hands out an address the router has spoken for', function () {
    actingAs(User::factory()->create())
        ->post(route('workspaces.store'), ['name' => 'Nieuw']);

    // /app/nieuw is this very screen, and /app/settings is the settings shell.
    // A workspace that claimed one would be a workspace nobody can open.
    expect(Workspace::where('name', 'Nieuw')->sole()->slug)
        ->not->toBeIn(Workspace::RESERVED_SLUGS);
});

it('asks for a name', function () {
    actingAs(User::factory()->create())
        ->post(route('workspaces.store'), ['name' => ''])
        ->assertSessionHasErrors('name');

    expect(Workspace::count())->toBe(0);
});

it('is not open to somebody who is not signed in', function () {
    $this->post(route('workspaces.store'), ['name' => 'Van Buiten'])
        ->assertRedirect(route('login'));

    expect(Workspace::count())->toBe(0);
});

it('draws the form rather than falling back to an empty shell', function () {
    // The page is mapped to the auth shell in app.tsx; a React error there
    // would leave SSR quietly rendering nothing and the Inertia prop
    // assertions above would still pass.
    skipWithoutSsr();

    $html = actingAs(User::factory()->create())
        ->get(route('workspaces.create'))
        ->getContent();

    expect($html)->toContain('Maak je workspace')
        ->and($html)->toContain('data-test="create-workspace-button"');
});
