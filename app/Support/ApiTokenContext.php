<?php

namespace App\Support;

use App\Models\ApiToken;
use App\Models\Workspace;
use Illuminate\Http\Request;

/**
 * Which token signed this request in, and what it was made for.
 *
 * AuthenticateApiToken puts the row on the request and calls Auth::setUser().
 * The user is the part every endpoint already had; this is the part the older
 * ones never needed to ask about — the one workspace a token may be tied to,
 * and the scopes it was granted.
 *
 * A handful of static readers rather than a request macro or a bound singleton.
 * A macro would be a method that exists on every Request in the application
 * including the several thousand that arrive through a session and have no
 * token at all; a singleton would be a second place holding what the request
 * already holds, and would have to be reset between tests. This is a named
 * spelling of `$request->attributes->get(...)`, and its whole value is that
 * the key is written once.
 *
 * Every reader is nullable and none of them throws. A missing token means the
 * request came in some other way, and answering "no token, no workspace, no
 * scope" is the safe reading of that in each case — the refusals live in the
 * middleware, where a refusal can still be an HTTP response.
 */
class ApiTokenContext
{
    /**
     * The attribute AuthenticateApiToken writes and everything here reads.
     *
     * On the request rather than on the authenticated user, because it is a
     * fact about this call and not about the person: the same member may hold
     * a workspace-bound token and an unbound one, and which of the two is
     * being used is exactly the question.
     */
    public const ATTRIBUTE = 'api_token';

    public static function token(Request $request): ?ApiToken
    {
        $token = $request->attributes->get(self::ATTRIBUTE);

        return $token instanceof ApiToken ? $token : null;
    }

    /**
     * The workspace this token was tied to, or null for a token that was not.
     *
     * Null is not an error and not an empty result: it is the older kind of
     * token, which speaks for its member in every workspace they belong to.
     * An endpoint that needs one workspace and gets null has to say which one
     * it means some other way — by asking for it in the request — rather than
     * treating the absence as a refusal.
     */
    public static function workspace(Request $request): ?Workspace
    {
        return self::token($request)?->workspace;
    }

    /**
     * Whether the token behind this request carries a scope.
     *
     * The same question RequireApiScope asks, available to a controller that
     * has to answer it about a second scope halfway through, rather than at
     * the door. False without a token, which is the honest answer for a
     * request that never carried one.
     */
    public static function allows(Request $request, string $scope): bool
    {
        return self::token($request)?->allows($scope) ?? false;
    }
}
