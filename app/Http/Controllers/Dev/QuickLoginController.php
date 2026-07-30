<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class QuickLoginController extends Controller
{
    /**
     * Sign in as a seeded account without a password.
     *
     * The route is only registered outside production, but the guard is
     * repeated here on purpose: an endpoint that logs anyone in without
     * credentials should not depend on a single line in a route file staying
     * correct forever.
     */
    public function __invoke(Request $request, User $user): RedirectResponse
    {
        abort_if(app()->isProduction(), 404);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }
}
