<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The front door of a platform that has nothing in it yet.
 *
 * Three addresses rather than every address, and the three are enough. Somebody
 * who has just put this on a server arrives at the home page, or at the login
 * screen a deploy tool printed, or at the sign-up form — and everything behind
 * the application funnels through that same login screen, so /app leads here in
 * two hops without this middleware knowing anything about /app.
 *
 * The alternative was to redirect the whole web group, and it is worth writing
 * down why that is wrong rather than merely broad. It would answer robots.txt
 * and sitemap.xml with a redirect, turn every 404 for a token that means
 * nothing into a 302, and make the public API reference unreadable. None of
 * those are doors somebody installs through; they are pages that happen to
 * share a middleware group with one.
 *
 * The sign-up form matters most of the three. Leaving it alone would let the
 * first person through the door make an ordinary account with no rights and no
 * workspace — which is precisely the outcome the onboarding screen exists to
 * prevent, and the one that used to need `php artisan user:promote` to undo.
 */
class RedirectToInstallation
{
    /**
     * The routes that mean "let me in", by the name they are registered under.
     * Two of them are Fortify's.
     *
     * @var array<int, string>
     */
    private const ENTRY_ROUTES = ['home', 'login', 'register'];

    public function __construct(private readonly Installation $installation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isEntrance($request) || ! $this->installation->pending()) {
            return $next($request);
        }

        return redirect()->route('install.show');
    }

    /**
     * Only what a person opens, never what a form posts. A POST to /login on an
     * empty platform already fails on its own terms — there is no account to
     * match — and a 302 to a different screen would turn that into a puzzle.
     */
    private function isEntrance(Request $request): bool
    {
        return $request->isMethod('GET')
            && in_array($request->route()?->getName(), self::ENTRY_ROUTES, true);
    }
}
