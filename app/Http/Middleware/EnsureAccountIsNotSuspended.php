<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a suspension into an actual lock-out, on every request rather than only
 * at the login screen.
 *
 * Checking here instead of in Fortify's login pipeline is deliberate. That
 * pipeline is skipped entirely by the two-factor challenge and by passkey
 * sign-in, so a suspended user with either of those configured would walk
 * straight past it. And it says nothing about the sessions that already exist
 * the moment a moderator pulls the lever — those people are signed in right now
 * and should be out on their next request, not whenever they happen to log in
 * again. One check on the way in covers all of that.
 *
 * The admin panel has its own middleware stack and is not covered here;
 * User::canAccessPanel() closes that door.
 */
class EnsureAccountIsNotSuspended
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuspended()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth_screens.login.suspended')], Response::HTTP_UNAUTHORIZED);
        }

        /**
         * Reported against the login field rather than flashed as a status: the
         * login screen renders a status in green, and this is not good news.
         */
        return redirect()->route('login')->withErrors([
            Fortify::username() => __('auth_screens.login.suspended'),
        ]);
    }
}
