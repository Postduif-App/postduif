<?php

use App\Enums\PlatformEdition;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * De publieke site hoort bij één installatie: die van postduif.app. Wie dit op
 * zijn eigen server zet, publiceert daarmee anders ongewild een landingspagina
 * die zijn eigen chat aanprijst — met een aanmeldknop erop.
 *
 * De suite draait als de gehoste uitgave, zodat de rest van de tests de pagina
 * kan blijven opvragen; zie phpunit.xml. Alles hieronder zet die schakelaar dus
 * expliciet, ook waar het antwoord "hosted" is.
 */
beforeEach(fn () => installedPlatform());

/**
 * De belofte die het makkelijkst stilletjes de andere kant op kan vallen: wie
 * niets instelt, hoort geen publieke site te krijgen.
 */
it('is self-hosted for an installation that never said anything', function () {
    config(['app.edition' => null]);

    expect(PlatformEdition::current())->toBe(PlatformEdition::SelfHosted)
        ->and(PlatformEdition::current()->showsMarketingSite())->toBeFalse();
});

/**
 * En bij een typefout in .env ook. Van de twee kanten waar dit op kan vallen is
 * dit de veilige: een installatie die per ongeluk geen reclame toont is een
 * ongemak, een installatie die er per ongeluk wel één toont is een probleem van
 * degene die hem draait.
 */
it('treats an edition it does not recognise as self-hosted', function () {
    config(['app.edition' => 'hostedd']);

    expect(PlatformEdition::current())->toBe(PlatformEdition::SelfHosted);
});

it('serves the landing page on the hosted edition', function () {
    config(['app.edition' => PlatformEdition::Hosted->value]);

    get(route('home'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/home'));
});

/**
 * Doorsturen en geen 404: dit is het hoofdadres van de installatie. Iemand die
 * postduif.eigenbedrijf.nl intypt wil naar zijn chat, en die een foutpagina
 * geven omdat er geen reclame te tonen valt is de verkeerde uitkomst.
 */
it('sends a stranger to the login screen when the marketing site is off', function () {
    config(['app.edition' => PlatformEdition::SelfHosted->value]);

    get(route('home'))->assertRedirect(route('login'));
});

it('sends a member straight to their workspace instead', function () {
    config(['app.edition' => PlatformEdition::SelfHosted->value]);

    $user = User::factory()->create();
    workspaceWithMember($user);

    actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('chat.home'));
});

/**
 * De API-pagina blijft staan, ook zonder marketingsite.
 *
 * Die beschrijft de installatie zelf — welke endpoints er zijn, wat ze
 * aannemen, hoeveel er per minuut in mag — en dat is precies wat iemand met een
 * eigen server nodig heeft om er een script op te zetten. Hem meenemen in het
 * uitzetten van de reclame zou een functie afpakken in plaats van een pagina.
 */
it('keeps the API reference on a self-hosted installation', function () {
    config(['app.edition' => PlatformEdition::SelfHosted->value]);

    get(route('docs'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('marketing/docs'));
});

it('asks crawlers to stay away from a self-hosted installation', function () {
    config(['app.edition' => PlatformEdition::SelfHosted->value]);

    $robots = get(route('robots'))->assertOk()->getContent();

    expect($robots)->toContain('Disallow: /')
        // En geen sitemap: er valt niets te crawlen, en het adres noemen is een
        // uitnodiging om te kijken.
        ->not->toContain('Sitemap:');

    $sitemap = get(route('sitemap'))->assertOk()->getContent();

    expect($sitemap)->not->toContain('<loc>');
});
