<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\ApiTokenContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sign an MCP request in as the member whose token it carries.
 *
 * Written here rather than pulled in with Sanctum: the application has no other
 * API, and this is one lookup on an indexed hash. What it must get right is the
 * same thing every token check must — that a revoked token is refused, and that
 * everything behind it runs as a real user so the policies apply.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearer($request);

        if ($token === null) {
            return $this->refuse();
        }

        $record = ApiToken::query()
            ->where('token_hash', ApiToken::hashToken($token))
            ->whereNull('revoked_at')
            ->with('user')
            ->first();

        if ($record === null || $record->user === null) {
            return $this->refuse();
        }

        /*
         * A token tied to one workspace is only worth what its member is still
         * worth there.
         *
         * Checked on every request rather than cleaned up when somebody leaves,
         * because leaving happens in half a dozen places — removed by an admin,
         * a workspace deleted, an account suspended and put back — and a
         * revocation that has to be remembered at each of them is a revocation
         * that will be forgotten at one of them. The row stays; what it opens
         * is decided here, now.
         *
         * A workspace that is gone lands here as well. The foreign key cascades
         * the token away with it, and if a row ever outlives its workspace by
         * some other route the membership it is asked about no longer exists —
         * so it refuses, rather than resolving to nothing and widening back to
         * "all workspaces", which is the failure mode worth being careful about.
         */
        if ($record->workspace_id !== null && ! $record->user->belongsToWorkspace($record->workspace_id)) {
            return $this->refuse();
        }

        /*
         * Stamped before the request runs rather than after: this is what a
         * member looks at to decide whether a token is still in use, and a
         * request that fails halfway is still a request that was made with it.
         */
        $record->forceFill(['last_used_at' => now()])->save();

        Auth::setUser($record->user);

        /*
         * The row itself travels on, not only the member behind it.
         *
         * Auth::setUser() answers "who" and nothing else, and a token now also
         * carries "where" and "what for". Handing the record over rather than
         * copying two values out of it means the next thing a token learns to
         * hold needs no change here — see ApiTokenContext, which is the only
         * place that knows this key.
         */
        $request->attributes->set(ApiTokenContext::ATTRIBUTE, $record);

        return $next($request);
    }

    /**
     * The token from the Authorization header.
     *
     * Both spellings, because clients differ and a client that sends "bearer"
     * in lower case is not wrong.
     */
    private function bearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization');

        if (preg_match('/^bearer\s+(.+)$/i', trim($header), $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * One answer for every failure: no header, a token nobody has, a revoked
     * one, or one whose account is gone. Telling them apart would be telling
     * somebody which of their guesses was closer.
     */
    private function refuse(): Response
    {
        return response()->json([
            'error' => __('mcp.token.invalid'),
        ], 401);
    }
}
