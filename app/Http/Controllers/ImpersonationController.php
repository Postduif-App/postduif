<?php

namespace App\Http\Controllers;

use App\Concerns\ResolvesCurrentWorkspace;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Signing in as somebody else, and signing back out of them.
 *
 * Outside the Settings namespace even though it is started from a settings
 * screen, because only half of it is a settings action. The way back has to
 * work from wherever the impersonator ends up — a channel, a document, the
 * inbox — and for whoever they are pretending to be, guest included. A route
 * that lived among the workspace screens would be one more thing a guest's
 * session is not allowed to reach.
 */
class ImpersonationController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Step into a member's session.
     *
     * Two gates before anything is written. The workspace is resolved from the
     * signed-in member as everywhere else, and then the target is judged on its
     * own — see WorkspacePolicy::impersonate, which is where the reach rule and
     * the four refusals live.
     *
     * The third gate is here rather than in the policy because it is a fact
     * about the session and not about the two people: an impersonation may not
     * be started from inside one. Nesting would make the way back a stack, and
     * a stack is a thing that can be left half-unwound — with somebody else's
     * account as the resting state.
     */
    public function store(Request $request, User $user, Impersonation $impersonation): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request, 'view');

        abort_if($impersonation->isActive(), 403);

        $this->authorize('impersonate', [$workspace, $user]);

        $impersonator = $request->user();

        /*
         * The only record there is. Nothing on any screen marks a message as
         * written while impersonating — that is what impersonating means — so
         * the log is the one place that can answer "wie was dat dan" after the
         * fact.
         */
        Log::info('Impersonation started', [
            'impersonator_id' => $impersonator->id,
            'target_id' => $user->id,
            'workspace_id' => $workspace->id,
        ]);

        $impersonation->begin($impersonator, $user);

        // Their workspace rather than the screen this was started from: the
        // point is to see what they see, and that begins at the chat.
        return redirect()->route('chat.home')->with('status', __('flashes.impersonation.started', [
            'name' => $user->name,
        ]));
    }

    /**
     * Be yourself again.
     *
     * No policy: the right to stop is the fact that it is running, and the
     * session is the only thing that can say so. A request that arrives when
     * nothing is running answers as if it had just finished, which is what
     * makes a second tab or a double click harmless.
     *
     * The one unhappy path is an impersonator who was suspended or deleted
     * while they were away. There is nobody to put back then, and leaving the
     * session as the impersonated member would turn an accident into a handover
     * of somebody's account — so the session ends entirely.
     */
    public function destroy(Request $request, Impersonation $impersonation): RedirectResponse
    {
        /*
         * Nothing running is not an error and not an unhappy path either: it is
         * a second tab, or a double click on the bar. Answered before anything
         * is touched, so that case cannot fall through to the logout below and
         * throw somebody out of their own session.
         */
        if (! $impersonation->isActive()) {
            return redirect()->route('chat.home');
        }

        $impersonated = $request->user();
        $impersonator = $impersonation->stop();

        if ($impersonator === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        Log::info('Impersonation ended', [
            'impersonator_id' => $impersonator->id,
            'target_id' => $impersonated?->id,
        ]);

        /*
         * Back to the ledenlijst, which is the only place this can be started
         * from — unless the right to open it has been taken away in the
         * meantime, in which case the chat is where everybody lands.
         */
        $workspace = $impersonator->workspaces()->oldest('workspace_user.joined_at')->first();

        $back = $workspace !== null && $impersonator->can('manageMembers', $workspace)
            ? route('workspace.members.index')
            : route('chat.home');

        return redirect()->to($back)->with('status', __('flashes.impersonation.stopped', [
            'name' => $impersonated->name,
        ]));
    }
}
