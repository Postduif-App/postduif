<?php

namespace App\Http\Controllers;

use App\Actions\Users\GenerateHandle;
use App\Actions\Workspace\AcceptInvitation;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Channel;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class InvitationController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * The landing page of a mailed invitation.
     *
     * Reachable while signed out — an invited guest has no account yet, so this
     * is the one door into the application they can open. The token in the URL
     * is the whole of their credentials until they have one.
     */
    public function show(Request $request, string $token): Response
    {
        $invitation = $this->find($token);

        return Inertia::render('auth/invitation', $this->pageFor($request, $invitation, $token));
    }

    /**
     * Accept it: join the workspace, and the channels that came with it.
     */
    public function accept(
        Request $request,
        string $token,
        AcceptInvitation $acceptInvitation,
        GenerateHandle $generateHandle,
    ): RedirectResponse {
        $invitation = $this->find($token);

        abort_if($invitation === null || ! $invitation->isPending(), 410);

        $user = $request->user();

        if ($user === null) {
            // The address already has an account, so this is not a sign-up. Send
            // them through the front door and bring them back here afterwards,
            // rather than letting a token stand in for a password.
            if (User::where('email', $invitation->email)->exists()) {
                $request->session()->put('url.intended', route('invitations.show', $token));

                return redirect()->route('login');
            }

            $user = $this->register($request, $invitation, $generateHandle);
        }

        // Signed in as somebody else. The invitation names one address, and
        // accepting it on another account is not a thing it can mean.
        abort_if($user->email !== $invitation->email, 403);

        $acceptInvitation->handle($invitation, $user);

        return redirect()
            ->route('chat.index', $invitation->workspace)
            ->with('status', __('flashes.invitation.welcome', ['workspace' => $invitation->workspace->name]));
    }

    /**
     * Create the account the invitation was addressed to.
     *
     * The e-mail is taken from the invitation rather than from the form: it is
     * the one thing about this person that was already decided. And it counts
     * as verified — reaching this page means they read mail sent to it, which
     * is exactly what a verification link proves.
     */
    private function register(Request $request, Invitation $invitation, GenerateHandle $generateHandle): User
    {
        $validated = Validator::make($request->all(), [
            'name' => $this->nameRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $validated['name'],
            'username' => $generateHandle->handle($validated['name'], $invitation->email),
            'email' => $invitation->email,
            'password' => $validated['password'],
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return $user;
    }

    /**
     * What the page has to show. An unknown, expired or spent token is not a
     * 404: the person holding it was invited by somebody, and "this link has
     * expired, ask for a new one" is a more useful answer than a dead end.
     *
     * @return array<string, mixed>
     */
    private function pageFor(Request $request, ?Invitation $invitation, string $token): array
    {
        if ($invitation === null) {
            return ['state' => 'unknown', 'invitation' => null, 'mode' => 'none'];
        }

        $viewer = $request->user();
        $existing = $viewer ?? User::where('email', $invitation->email)->first();

        $state = match (true) {
            $invitation->isAccepted() => 'accepted',
            $invitation->hasExpired() => 'expired',
            default => 'pending',
        };

        return [
            'state' => $state,
            'token' => $token,
            // Which of the three ways in this visitor is looking at: they are
            // already signed in as the invitee, they have an account and need
            // to sign in, or they have to be given one.
            'mode' => match (true) {
                $state !== 'pending' => 'none',
                $viewer !== null && $viewer->email === $invitation->email => 'accept',
                $viewer !== null => 'mismatch',
                $existing !== null => 'login',
                default => 'register',
            },
            'currentEmail' => $viewer?->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'invitation' => [
                'email' => $invitation->email,
                'workspaceName' => $invitation->workspace->name,
                'invitedBy' => $invitation->inviter->name,
                'role' => $invitation->workspace_role_id,
                'roleLabel' => $invitation->workspaceRole?->name,
                'isGuest' => $invitation->workspaceRole->is_external ?? false,
                'channels' => $invitation->channels()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Channel $channel): string => (string) $channel->name)
                    ->all(),
            ],
        ];
    }

    private function find(string $token): ?Invitation
    {
        return Invitation::with(['workspace', 'inviter'])
            ->where('token', $token)
            ->first();
    }
}
