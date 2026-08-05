<?php

use App\Actions\Marketing\BuildApiReference;
use Illuminate\Routing\Router;
use Inertia\Testing\AssertableInertia;

/**
 * The API reference at /docs.
 *
 * The test that earns its place is the pairing one below: the page is built
 * from the router, so an endpoint added without a line written about it must
 * fail here rather than quietly go missing from the documentation. That is the
 * whole reason the prose is keyed by route name instead of typed into the page.
 */
it('is open to somebody with no account', function () {
    // The endpoints are guarded by a token nobody gets from reading about
    // them, and an API whose shape is a secret is one nobody can build against.
    $this->get(route('docs'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/docs'));
});

it('documents every route it claims to cover, and claims no route that is gone', function () {
    $router = app(Router::class);

    $registered = collect($router->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => str_starts_with($name, 'api.v1.')
            || $name === 'webhooks.messages.store'
            || $name === 'workflows.webhook')
        ->sort()
        ->values()
        ->all();

    /*
     * Both directions on purpose. A missing entry means a page that is short an
     * endpoint; a leftover one means a page advertising something the router no
     * longer answers to, which is the worse of the two.
     */
    expect(collect(BuildApiReference::documented())->sort()->values()->all())
        ->toBe($registered);
});

it('reads the method and the path off the router rather than being told', function () {
    $reference = app(BuildApiReference::class)->handle(app(Router::class));

    $status = collect($reference['endpoints'])
        ->firstWhere('name', 'api.v1.status.update');

    expect($status['method'])->toBe('PATCH')
        ->and($status['path'])->toBe('/api/v1/status');
});

it('leaves HEAD out, which rides along with every GET and says nothing', function () {
    $reference = app(BuildApiReference::class)->handle(app(Router::class));

    $channels = collect($reference['endpoints'])
        ->firstWhere('name', 'api.v1.channels.index');

    expect($channels['method'])->toBe('GET');
});

it('takes the ceilings from the limiters rather than repeating them', function () {
    $reference = app(BuildApiReference::class)->handle(app(Router::class));

    /*
     * The numbers themselves live in AppServiceProvider. Asserting them here
     * would be the second copy this action exists to avoid, so this only checks
     * that a real limit came back and that the workflow trigger is on the
     * shorter leash — which is a decision, not a number.
     */
    expect($reference['limits']['api-token']['perMinute'])->toBeGreaterThan(0)
        ->and($reference['limits']['workflow-webhook']['perMinute'])
        ->toBeLessThan($reference['limits']['webhook']['perMinute']);
});

it('hands the page an endpoint with everything it needs to draw it', function () {
    $this->get(route('docs'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('endpoints')
            ->has('limits')
            ->has('tools')
            ->has('endpoints.0.method')
            ->has('endpoints.0.path')
            ->has('endpoints.0.summary')
            ->has('endpoints.0.auth'));
});

it('sorts each endpoint under the key that opens it', function () {
    $reference = app(BuildApiReference::class)->handle(app(Router::class));

    $byName = collect($reference['endpoints'])->keyBy('name');

    // The v1 routes sit behind a personal token; the two webhooks carry their
    // key in the path instead, which is a different section of the page.
    expect($byName['api.v1.messages.store']['auth'])->toBe('token')
        ->and($byName['webhooks.messages.store']['auth'])->toBe('webhook')
        ->and($byName['workflows.webhook']['auth'])->toBe('webhook');
});
