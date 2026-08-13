<?php

use App\Enums\ChannelDocumentPolicy;
use App\Enums\ChannelLayout;
use App\Enums\ChannelPostingPolicy;
use App\Enums\ChannelTicketPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Features\Forms;
use App\Features\Transfers;
use App\Features\WorkspaceFeature;
use App\Models\User;
use App\Workflows\Actions\HttpRequest;
use App\Workflows\WorkflowRegistry;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The public site is what an installed platform shows a stranger. An empty one
 * shows the onboarding screen instead, on these very addresses — see
 * RedirectToInstallation — so every test below needs the platform to exist
 * before it can say anything about the pages it serves.
 */
beforeEach(fn () => installedPlatform());

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
        ->toContain(Forms::key())
        /*
         * Nine, and each one hands out something a workspace should grant on
         * purpose rather than find already granted: the ones that let something
         * reach past the workspace — transfers, secrets, a form behind a public
         * link, a contract sent out to be signed, a channel opened to another
         * workspace — workflows, which stay inside it but act on channels with
         * the rights of whoever wrote them, the clock, which records people,
         * and huddles, which need a relay arranged before they work for
         * everybody.
         */
        ->toHaveCount(9);
});

it('describes the roles as the code seeds them', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('roles', count(SystemRole::cases()))
            ->where('roles.3.value', SystemRole::Guest->value)
            // The guest column is the one that matters: it is the only role the
            // application actively keeps out of things, and browsing is the
            // answer that is a column on the role rather than a right in its
            // bag — which is why the page gives it its own row.
            ->where('roles.3.canBrowseWorkspace', false)
            ->where('roles.3.abilities', [])
            /*
             * The owner holds the whole catalogue, and this is the assertion
             * that keeps the page honest about custom roles: nobody may write
             * into a role a right they do not hold themselves, so a right no
             * seeded role holds is one no workspace could ever switch on.
             */
            ->where('roles.0.abilities', array_column(WorkspaceAbility::cases(), 'value'))
        );
});

it('offers the whole catalogue of rights a custom role can be built from', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            /*
             * Every case, not a chosen few. The table used to name five rights
             * by hand and went on naming five once the catalogue grew past
             * them; reading the enum is what stops that happening twice.
             */
            ->has('abilities', count(WorkspaceAbility::cases()))
            ->where('abilities.0.value', WorkspaceAbility::ManageWorkspace->value)
            ->where('abilities.0.label', WorkspaceAbility::ManageWorkspace->label())
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
    skipWithoutSsr();

    $response = get(route($route))->assertOk();

    expect($response->getContent())
        // The brand scope, which is what remaps the shadcn tokens.
        ->toContain('postduif')
        // The dove, by the path the huisstijl defines it with.
        ->toContain('M30.5 7.6C35.6')
        // And not the framework's own mark.
        ->not->toContain('M17.2 5.63325');
})->with(['login', 'register', 'password.request']);

/**
 * The half of the application that has no feature switch.
 *
 * A channel is not something anybody turns on, so none of it appears in the
 * inventory — while it is where the whole day is spent. Read off the same enums
 * the settings screen draws its dropdowns from, so a case added there shows up
 * here without anybody editing a page.
 */
it('shows how a channel can be set up, in the words the settings screen uses', function () {
    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('channelSettings.layout', count(ChannelLayout::cases()))
            ->has('channelSettings.posting', count(ChannelPostingPolicy::cases()))
            ->has('channelSettings.tickets', count(ChannelTicketPolicy::cases()))
            ->has('channelSettings.documents', count(ChannelDocumentPolicy::cases()))
            ->where('channelSettings.layout.0.label', ChannelLayout::cases()[0]->getLabel())
        );
});

it('names every trigger and every action a workflow can be built from', function () {
    $registry = app(WorkflowRegistry::class);

    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('workflow.triggers', count($registry->triggers()))
            ->has('workflow.actions', count($registry->actions()))
        );

    /*
     * The point of reading the register rather than a list: an action taken out
     * of the runner stops being advertised on the same deploy, and one added
     * starts being advertised without anybody remembering to.
     */
    $labels = collect(get(route('home'))->viewData('page')['props']['workflow']['actions'])
        ->pluck('label');

    expect($labels)->toContain(HttpRequest::label());
});

it('shows what a personal token opens, as the router has it', function () {
    $response = get(route('home'))->assertOk();

    $endpoints = collect($response->viewData('page')['props']['token']['endpoints'])
        ->map(fn (array $endpoint): string => "{$endpoint['method']} {$endpoint['path']}");

    expect($endpoints)->toContain('POST /api/v1/messages')
        // HEAD comes along with every GET and says nothing anybody reads.
        ->and($endpoints->filter(fn (string $line): bool => str_contains($line, 'HEAD')))
        ->toBeEmpty();

    $tools = collect($response->viewData('page')['props']['token']['tools'])->pluck('name');

    expect($tools)->toHaveCount(4);
});
