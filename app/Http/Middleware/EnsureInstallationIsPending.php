<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The onboarding screen exists for exactly as long as it is needed.
 *
 * On the route rather than in the controller, and on the POST as much as the
 * GET. Hiding a form is presentation; this is the part that decides whether a
 * platform-wide moderator can still be conjured out of an anonymous request,
 * and a posted form never goes through the view.
 */
class EnsureInstallationIsPending
{
    public function __construct(private readonly Installation $installation) {}

    public function handle(Request $request, Closure $next): Response
    {
        // 404 rather than a redirect: on an installed platform this address
        // simply is not a thing, and saying so is more honest than sending
        // somebody to a login screen they did not ask for.
        abort_unless($this->installation->pending(), 404);

        return $next($request);
    }
}
