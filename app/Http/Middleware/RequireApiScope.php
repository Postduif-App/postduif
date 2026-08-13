<?php

namespace App\Http\Middleware;

use App\Support\ApiTokenContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ask the token whether it was made for this.
 *
 * Always behind AuthenticateApiToken, never instead of it: that one decides
 * whether the caller is anybody at all, this one whether the key they used was
 * cut for this door. Two middlewares rather than one with an optional argument
 * because the answers are different — an unknown token is 401 and "try again
 * with a credential", a token without the scope is 403 and "this credential
 * will never open this, make another one".
 *
 * Refuses a request with no token at all, which is the case where this is
 * mounted on a route that forgot the token middleware. Silently allowing it
 * would make a missing `api.token` look like a working configuration.
 *
 * Note what it deliberately does not do: grant anything. A scope narrows a
 * token, it does not widen a member — everything past here still runs as the
 * person and still meets the policies. A contracts scope on the token of
 * somebody who may not send contracts opens nothing.
 */
class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        if (! ApiTokenContext::allows($request, $scope)) {
            /*
             * The same shape as the refusal one door back, on purpose: a client
             * reading these gets one error format from this API, and the status
             * code is where the difference is said.
             */
            return response()->json([
                'error' => __('mcp.token.scope_missing', ['scope' => $scope]),
            ], 403);
        }

        return $next($request);
    }
}
