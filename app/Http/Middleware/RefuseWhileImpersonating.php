<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The doors that stay shut while somebody is signed in as somebody else.
 *
 * Impersonation is meant to be a way of *looking*: what does this member see,
 * why is their sidebar empty, where did that notification go. Everything on the
 * list below is instead a way of leaving something behind that outlives the
 * visit — a personal API token that keeps acting as them tomorrow, a passkey on
 * the impersonator's own laptop, two-factor turned off, an email address
 * changed to one that can request a password reset.
 *
 * None of that is a bug in impersonation; it is what being somebody else means.
 * Which is exactly why it needs a fence: without one, a right meant for support
 * work is a right to walk off with an account, and the person it happened to
 * has no way of finding out.
 *
 * Matched on the path rather than the route name, because most of these routes
 * are registered by Fortify and by the passkey package. Their names are not
 * ours to rely on, and a middleware attached to a package's route group is a
 * middleware that quietly stops running when the package rearranges itself. A
 * path is a promise the browser makes.
 *
 * Global rather than on the routes for the same reason — and because this is a
 * refusal that has to be complete: a route it forgot is not a mild oversight
 * but the whole hole.
 */
class RefuseWhileImpersonating
{
    /**
     * Everything that changes how an account is signed into, wherever it lives.
     *
     * `user/*` is Fortify's and the passkey package's corner: two-factor,
     * recovery codes, registered passkeys and password confirmation.
     * `passkeys/*` is where a passkey is used to sign in or to confirm, which
     * would let an impersonator confirm with their own finger.
     *
     * @var list<string>
     */
    private const ALWAYS = [
        'user/*',
        'passkeys/*',
        'app/settings/security',
        'app/settings/password',
        'app/settings/api-tokens',
        'app/settings/api-tokens/*',
    ];

    /**
     * The profile, but only when it is being written.
     *
     * Reading it stays open, because "wat staat er bij deze persoon" is one of
     * the questions this feature exists to answer. Writing does not: an email
     * address is the address a password reset goes to, and deleting the account
     * is not something to be able to do on somebody's behalf by accident.
     *
     * @var list<string>
     */
    private const WHEN_WRITTEN = [
        'app/settings/profile',
    ];

    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonation->isActive()) {
            return $next($request);
        }

        $refused = $request->is(...self::ALWAYS)
            || (! $request->isMethodSafe() && $request->is(...self::WHEN_WRITTEN));

        abort_if($refused, Response::HTTP_FORBIDDEN, __('account.impersonation.refused'));

        return $next($request);
    }
}
