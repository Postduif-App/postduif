<?php

namespace App\Http\Controllers;

use App\Actions\Install\InstallApplication;
use App\Http\Requests\StoreInstallationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The first screen of a platform nobody has set up yet.
 *
 * Until now the only way to make the first moderator was `php artisan
 * user:promote` on the server — see PromoteUser, which says as much. That works
 * for whoever deployed it and for nobody else, and it leaves the ordinary path
 * through the sign-up form making an account with no rights and no workspace.
 *
 * So this is the door, and it is only a door once: EnsureInstallationIsPending
 * takes both routes away the moment there is a workspace or a moderator, and
 * RedirectToInstallation makes sure that until then every other address leads
 * here.
 */
class InstallController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('install/welcome', [
            /*
             * What the browser should ask of the password before the server
             * refuses it. The same prop the join screen passes, read off
             * Password::defaults() rather than written out, so an installation
             * that raises the bar does not end up with a page describing the
             * old one.
             */
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(StoreInstallationRequest $request, InstallApplication $install): RedirectResponse
    {
        $user = $install->handle($request->fields());

        Auth::login($user);
        $request->session()->regenerate();

        /*
         * Through chat.home rather than straight at the workspace: it already
         * knows how to pick which one somebody lands in, and there is no reason
         * for this screen to hold a second opinion about that.
         */
        return redirect()
            ->route('chat.home')
            ->with('status', __('install.installed', ['name' => $user->name]));
    }
}
