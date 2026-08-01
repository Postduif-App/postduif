<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\InviteToWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Inviting somebody into the workspace, from the chat interface itself. The
 * workspace is named in the URL rather than guessed from the signed-in member,
 * so this keeps working the day somebody belongs to more than one.
 */
class WorkspaceInvitationController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        InviteToWorkspace $inviteToWorkspace,
    ): RedirectResponse {
        $this->authorize('invite', $workspace);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', $this->notAlreadyIn($workspace)],
            'role' => ['required', new Enum(WorkspaceRole::class)],

            // A guest sees nothing but the channels named here, so an empty
            // list would be an invitation into a workspace with nothing in it.
            'channel_ids' => [
                'array',
                'max:50',
                Rule::requiredIf(fn (): bool => $request->input('role') === WorkspaceRole::Guest->value),
            ],
            'channel_ids.*' => ['integer'],
        ], [
            'channel_ids.required' => 'Kies minstens één kanaal voor deze gast.',
        ]);

        $role = WorkspaceRole::from($validated['role']);

        $this->authorize('grantRole', [$workspace, $role]);

        $inviteToWorkspace->handle(
            $workspace,
            $request->user(),
            $validated['email'],
            $role,
            $validated['channel_ids'] ?? [],
        );

        return back()->with('status', 'Uitnodiging verstuurd naar '.$validated['email'].'.');
    }

    /**
     * Send the same invitation again. The link in the earlier mail stops
     * working, which is the point: a token living on in an old inbox is
     * precisely what an expiry date is for.
     */
    public function resend(
        Request $request,
        Workspace $workspace,
        Invitation $invitation,
        InviteToWorkspace $inviteToWorkspace,
    ): RedirectResponse {
        $this->authorize('invite', $workspace);

        abort_unless($invitation->workspace_id === $workspace->id, 404);

        $inviteToWorkspace->handle(
            $workspace,
            $request->user(),
            $invitation->email,
            $invitation->role,
            $invitation->channels()->pluck('channels.id'),
        );

        return back()->with('status', 'Uitnodiging opnieuw verstuurd naar '.$invitation->email.'.');
    }

    public function destroy(Workspace $workspace, Invitation $invitation): RedirectResponse
    {
        $this->authorize('invite', $workspace);

        abort_unless($invitation->workspace_id === $workspace->id, 404);

        $invitation->delete();

        return back()->with('status', 'Uitnodiging voor '.$invitation->email.' ingetrokken.');
    }

    /**
     * Somebody who is already here does not need an invitation, and accepting
     * one would be a way to hand an existing member a guest's role.
     */
    private function notAlreadyIn(Workspace $workspace): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($workspace): void {
            $user = User::where('email', mb_strtolower(trim((string) $value)))->first();

            if ($user !== null && $workspace->hasMember($user)) {
                $fail('Deze persoon zit al in de workspace.');
            }
        };
    }
}
