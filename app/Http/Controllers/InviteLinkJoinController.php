<?php

namespace App\Http\Controllers;

use App\Actions\Users\GenerateHandle;
use App\Actions\Workspace\RedeemInviteLink;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Channel;
use App\Models\InviteLink;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public half of an invite link: following one.
 *
 * Reachable while signed out — that is the whole point of a link you can share
 * — so the token in the URL is the only credential there is until an account
 * exists. Managing the links themselves is InviteLinkController, behind auth.
 *
 * The difference with a mailed invitation is what is not known here: nobody
 * decided in advance who this is. So the address is asked for rather than
 * fixed, and it is not treated as verified — reaching this page proves you were
 * given a link, not that you read mail sent to that address.
 */
class InviteLinkJoinController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    public function show(Request $request, string $token): Response
    {
        $link = $this->find($token);

        /*
         * Where to come back to after signing in. Somebody who already has an
         * account has no business filling in the registration form, and this is
         * what makes "log in first" land back on the link rather than in a
         * workspace they were already in.
         */
        if ($request->user() === null) {
            $request->session()->put('url.intended', route('invite-links.show', $token));
        }

        return Inertia::render('auth/join', $this->pageFor($request, $link, $token));
    }

    /**
     * Use it: sign up if needed, then join.
     */
    public function join(
        Request $request,
        string $token,
        RedeemInviteLink $redeemInviteLink,
        GenerateHandle $generateHandle,
    ): RedirectResponse {
        $link = $this->find($token);

        abort_if($link === null || ! $link->isUsable(), 410);

        $user = $request->user() ?? $this->register($request, $generateHandle);

        /*
         * Checked again inside the action, under a lock. A 410 here would be a
         * lie to somebody who just made an account, so a link that ran out
         * between the two checks sends them back to the page, which now says
         * what happened.
         */
        if (! $redeemInviteLink->handle($link, $user)) {
            return redirect()->route('invite-links.show', $token);
        }

        return redirect()
            ->route('chat.index', $link->workspace)
            ->with('status', __('flashes.invitation.welcome', ['workspace' => $link->workspace->name]));
    }

    /**
     * Create the account for somebody the workspace was not expecting.
     *
     * Unlike an invited account this one is not marked verified: the address
     * came from the form, and nothing has proved it belongs to whoever typed
     * it. What the link proves is that they were given the link.
     */
    private function register(Request $request, GenerateHandle $generateHandle): User
    {
        $validated = Validator::make($request->all(), [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $validated['name'],
            'username' => $generateHandle->handle($validated['name'], $validated['email']),
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * What the page has to show. A link that no longer works is not a 404: the
     * person holding it was handed it by somebody, and saying which of the
     * three things went wrong is more useful than a dead end.
     *
     * @return array<string, mixed>
     */
    private function pageFor(Request $request, ?InviteLink $link, string $token): array
    {
        if ($link === null) {
            return ['state' => 'unknown', 'mode' => 'none', 'link' => null];
        }

        $state = match (true) {
            $link->isRevoked() => 'revoked',
            $link->hasExpired() => 'expired',
            $link->isExhausted() => 'exhausted',
            default => 'usable',
        };

        $viewer = $request->user();

        return [
            'state' => $state,
            'token' => $token,
            // Signed in, so there is nothing to fill in; or not, and an account
            // has to be made first. There is no third case: a link names
            // nobody, so it can never be meant for somebody else.
            'mode' => match (true) {
                $state !== 'usable' => 'none',
                $viewer !== null => 'accept',
                default => 'register',
            },
            'currentEmail' => $viewer?->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'link' => [
                'workspaceName' => $link->workspace->name,
                'invitedBy' => $link->creator?->name,
                'roleLabel' => $link->workspaceRole?->name,
                'isGuest' => $link->workspaceRole->is_external ?? false,
                'channels' => $link->channels()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Channel $channel): string => (string) $channel->name)
                    ->all(),
            ],
        ];
    }

    private function find(string $token): ?InviteLink
    {
        return InviteLink::with(['workspace', 'creator'])
            ->where('token', $token)
            ->first();
    }
}
