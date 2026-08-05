<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleLocale;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Asking for the other language.
 *
 * HandleLocale already answers in the language a browser asks for, which
 * covers almost everybody almost always. What it cannot do is take no for an
 * answer: somebody reading Dutch on an English laptop had nowhere to say so,
 * and on the public site there is no account to save it against.
 *
 * A cookie rather than the session, so the choice outlives the session a
 * signed-out visitor never knew they had. It sits below a member's own setting
 * — see HandleLocale — because that one was chosen on a screen about exactly
 * this, and a cookie should not quietly overrule it.
 */
class LocaleController extends Controller
{
    /** A year: long enough that nobody has to choose twice, short enough to lapse. */
    private const REMEMBER_MINUTES = 525_600;

    /**
     * A GET rather than a form post, and deliberately.
     *
     * It has to be a link: the switcher sits in the header of a public page
     * that a reader may arrive at with JavaScript still loading, and a
     * language they cannot read is the one case where the page must work
     * anyway. Writing on a GET is the price, and it is a small one here — the
     * write is idempotent, it holds nothing but a language, and there is
     * nothing behind it worth forging.
     */
    public function __invoke(string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, HandleLocale::SUPPORTED, true), 404);

        return back()->withCookie(
            /*
             * Plain rather than encrypted, like the appearance cookie beside it
             * — see bootstrap/app.php. There is nothing in "nl" worth hiding,
             * and a cookie the browser can read is one the first paint can act
             * on rather than wait for.
             */
            cookie(
                name: HandleLocale::COOKIE,
                value: $locale,
                minutes: self::REMEMBER_MINUTES,
                sameSite: Cookie::SAMESITE_LAX,
            )
        );
    }
}
