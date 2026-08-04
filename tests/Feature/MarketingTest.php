<?php

use App\Enums\WorkspaceRole;
use App\Features\Transfers;
use App\Features\WorkspaceFeature;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('opens for somebody with no account at all', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/home'));
});

/**
 * Everything factual on the page is read off the feature classes. A page
 * maintained by hand becomes a promise the code has stopped keeping, and nobody
 * notices until a customer does.
 */
it('lists exactly the features the application has', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('features', count(WorkspaceFeature::ALL))
            ->where('features.0.label', WorkspaceFeature::ALL[0]::label())
            ->where('features.0.description', WorkspaceFeature::ALL[0]::description())
        );
});

/**
 * Adding a feature class must change the page without anybody editing it. This
 * is the assertion that keeps the derivation honest rather than decorative.
 */
it('names no feature the code does not have', function () {
    $response = get(route('home'))->assertOk();

    $labels = collect($response->viewData('page')['props']['features'])
        ->pluck('label')
        ->all();

    $expected = array_map(
        fn (string $feature): string => $feature::label(),
        WorkspaceFeature::ALL,
    );

    expect($labels)->toBe($expected);
});

it('says which features only exist once somebody switches them on', function () {
    $response = get(route('home'))->assertOk();

    $off = collect($response->viewData('page')['props']['features'])
        ->where('onByDefault', false)
        ->pluck('key')
        ->all();

    expect($off)->toContain(Transfers::key())
        /*
         * Four, and each one hands out something a workspace should grant on
         * purpose rather than find already granted: three that let something
         * reach past the workspace, and workflows — which stay inside it, but
         * act on channels with the rights of whoever wrote them.
         */
        ->toHaveCount(4);
});

it('describes the roles as the code defines them', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('roles', count(WorkspaceRole::cases()))
            ->where('roles.3.value', WorkspaceRole::Guest->value)
            // The guest row is the one that matters: it is the only role the
            // application actively keeps out of things.
            ->where('roles.3.canBrowseWorkspace', false)
            ->where('roles.3.canCreateChannels', false)
        );
});

/**
 * The layout reaches for auth.user, and a signed-out visitor has none. This is
 * the case a developer who is always logged in never sees.
 */
it('carries no workspace for a visitor who is not signed in', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user', null)
            ->where('auth.workspace', null)
        );
});

it('still opens for somebody who is signed in', function () {
    $user = User::factory()->create();
    workspaceWithMember($user);

    actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/home'));
});

/**
 * The screens somebody meets from outside: logging in, accepting an invitation,
 * downloading files, filling in a request for secrets. Several of these are the
 * only thing a customer ever sees of Postduif, so they carry the brand rather
 * than the application's own look.
 */
it('dresses the external screens in the house style', function (string $route) {
    $response = get(route($route))->assertOk();

    expect($response->getContent())
        // The brand scope, which is what remaps the shadcn tokens.
        ->toContain('postduif')
        // The dove, by the path the huisstijl defines it with.
        ->toContain('M30.5 7.6C35.6')
        // And not the framework's own mark.
        ->not->toContain('M17.2 5.63325');
})->with(['login', 'register', 'password.request']);
