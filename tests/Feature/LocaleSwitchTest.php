<?php

use App\Http\Middleware\HandleLocale;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Asking for the other language.
 *
 * HandleLocale already answers in the language a browser asks for, which covers
 * almost everybody almost always. What it could not do is take no for an
 * answer: a Dutch reader on an English laptop had nowhere to say so, and on the
 * public site there is no account to save it against.
 *
 * The language a page came back in is read off <html lang>, which is the one
 * place it is stated outright rather than inferred from a sentence.
 */
function languageOf(string $html): string
{
    preg_match('/<html lang="([^"]+)"/', $html, $matches);

    return $matches[1] ?? '';
}

it('remembers the language somebody asked for', function () {
    $this->from(route('home'))
        ->get(route('locale.switch', 'en'))
        ->assertRedirect(route('home'))
        // Plain rather than encrypted, like the appearance cookie beside it —
        // see bootstrap/app.php.
        ->assertPlainCookie(HandleLocale::COOKIE, 'en');
});

it('answers in the language the cookie asked for, over the browser', function () {
    // The header is a preference expressed once and forgotten; the cookie is
    // somebody pressing a button on this page a moment ago.
    $html = $this->withUnencryptedCookie(HandleLocale::COOKIE, 'en')
        ->withHeader('Accept-Language', 'nl')
        ->get(route('home'))
        ->getContent();

    expect(languageOf($html))->toBe('en');
});

it('does not overrule a member who set their language', function () {
    // The settings screen asks this question outright. A link pressed on the
    // public site should not quietly answer it differently.
    $html = actingAs(User::factory()->create(['locale' => 'nl']))
        ->withUnencryptedCookie(HandleLocale::COOKIE, 'en')
        ->get(route('home'))
        ->getContent();

    expect(languageOf($html))->toBe('nl');
});

it('still follows the cookie for a member who follows their browser', function () {
    // locale null means "follow my browser", so there is no answer to overrule.
    $html = actingAs(User::factory()->create(['locale' => null]))
        ->withUnencryptedCookie(HandleLocale::COOKIE, 'en')
        ->withHeader('Accept-Language', 'nl')
        ->get(route('home'))
        ->getContent();

    expect(languageOf($html))->toBe('en');
});

it('refuses a language it does not have', function () {
    $this->from(route('home'))
        ->get(route('locale.switch', 'fr'))
        ->assertNotFound()
        ->assertCookieMissing(HandleLocale::COOKIE);
});

it('offers the other language on the public site', function () {
    skipWithoutSsr();

    $html = $this->withHeader('Accept-Language', 'nl')->get(route('home'))->getContent();

    /*
     * Relative, because that is what the wayfinder helpers emit — asserting the
     * absolute route() would pass only on a host that happens to match.
     *
     * The one somebody is already reading is not a link; the other one is.
     */
    expect($html)->toContain('href="'.route('locale.switch', 'en', absolute: false).'"')
        ->and($html)->not->toContain('href="'.route('locale.switch', 'nl', absolute: false).'"')
        // And it says which one you are on, for somebody who cannot read it.
        ->and($html)->toContain('aria-current="true"');
});
