<?php

use App\Actions\Marketing\BuildFeatureInventory;

/**
 * What the public pages tell a crawler and a chat client.
 *
 * All of it is asserted against the rendered HTML rather than against the
 * Inertia props, because that is the whole point: a preview card is fetched by
 * something that does not run JavaScript, so a tag that only appeared after
 * hydration would be a tag nobody outside a browser ever sees.
 */
it('gives the homepage a description, a canonical address and a card image', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('<meta name="description"', false)
        ->assertSee('<link rel="canonical" href="'.route('home').'">', false)
        ->assertSee('<meta property="og:image" content="'.url('/og.png').'">', false)
        // summary_large_image rather than summary: the image is 1200x630, and
        // the small card would letterbox it into a thumbnail.
        ->assertSee('name="twitter:card" content="summary_large_image"', false);
});

it('does not put the app name in the title twice', function () {
    // The title is written by Inertia's head, which only reaches the HTML when
    // the renderer is running.
    skipWithoutSsr();

    // Inertia appends " - Postduif" to whatever a page asks for, so a page that
    // asks for "Postduif" gets it said twice — which is a wasted title tag.
    $response = $this->get(route('home'));

    expect($response->getContent())
        ->toContain('<title data-inertia="">Het gesprek en het werk op één plek - Postduif</title>');
});

it('gives the API reference its own description rather than the homepage one', function () {
    $home = $this->get(route('home'))->getContent();
    $docs = $this->get(route('docs'))->getContent();

    expect($docs)->toContain('<link rel="canonical" href="'.route('docs').'">');

    // Two pages sharing one description is two pages competing for the same
    // search result, which is the commonest way a second page earns nothing.
    $describe = fn (string $html): string => preg_match(
        '/<meta name="description" content="([^"]*)"/', $html, $m
    ) === 1 ? $m[1] : '';

    expect($describe($docs))->not->toBe('')
        ->and($describe($docs))->not->toBe($describe($home));
});

it('says nothing to a crawler on the pages behind the login', function () {
    // The application has nothing to tell a search engine, and a canonical tag
    // on a login screen is an invitation to index it.
    $login = $this->get('/login')->getContent();

    expect($login)->not->toContain('og:title')
        ->and($login)->not->toContain('application/ld+json')
        ->and($login)->not->toContain('rel="canonical"');
});

it('describes itself in structured data the page itself supplied', function () {
    $html = $this->get(route('home'))->getContent();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    $schema = json_decode($matches[1] ?? '', true);

    expect($schema)->toBeArray()
        ->and($schema['@type'])->toBe('SoftwareApplication');

    /*
     * The feature list comes off the same inventory the page renders, so the
     * structured data cannot claim something the page does not show. That is
     * the same rule the rest of the public site is built on — see
     * MarketingController.
     */
    expect($schema['featureList'])
        ->toBe(array_column(app(BuildFeatureInventory::class)->handle(), 'label'));
});

it('keeps crawlers out of the application and points them at the sitemap', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    expect($response->getContent())
        ->toContain('Disallow: /app')
        // Every address that carries a token is a thing rather than a page.
        ->toContain('Disallow: /transfers/')
        ->toContain('Disallow: /join/')
        // Absolute, because robots.txt allows nothing else here.
        ->toContain('Sitemap: '.route('sitemap'));
});

it('lists the public pages in the sitemap and nothing that carries a token', function () {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/xml');

    $xml = $response->getContent();

    expect($xml)->toContain('<loc>'.route('home').'</loc>')
        ->toContain('<loc>'.route('docs').'</loc>')
        ->not->toContain('/join/')
        ->not->toContain('/transfers/')
        ->not->toContain('/app');
});

it('builds both off the host it was asked on rather than a baked-in one', function () {
    /*
     * This application is installed under whatever hostname somebody puts it
     * on. A sitemap written out as a file would name the machine it was written
     * on, which for every other installation is a sitemap pointing at somebody
     * else's site — which is why these are routes.
     */
    $xml = $this->get('http://elders.test/sitemap.xml')->getContent();

    expect($xml)->toContain('http://elders.test');
});

it('has no static robots file that would answer before PHP does', function () {
    // A file in public/ is served by the web server before the request reaches
    // Laravel, so one sitting there would silently win over the route above.
    expect(file_exists(public_path('robots.txt')))->toBeFalse();
});
