<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\InviteToWorkspace;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
            /*
             * A role of this workspace, by its own id. Scoped in the rule
             * rather than checked afterwards: without the workspace_id an id
             * from somewhere else would name a role that exists, and the only
             * thing standing between that and an invitation into the wrong
             * workspace is the policy below.
             */
            'role' => [
                'required',
                'integer',
                Rule::exists('workspace_roles', 'id')->where('workspace_id', $workspace->id),
            ],

            /*
             * Somebody from outside sees nothing but the channels named here,
             * so an empty list would be an invitation into a workspace with
             * nothing in it. Asked of the role rather than of a name: a
             * workspace may call its outside role anything.
             */
            'channel_ids' => [
                'array',
                'max:50',
                Rule::requiredIf(fn (): bool => $this->rolePointsOutside($request->input('role'), $workspace)),
            ],
            'channel_ids.*' => ['integer'],
        ], [
            'channel_ids.required' => __('requests.invite.channels_required'),
        ]);

        $role = $workspace->roles()->whereKey($validated['role'])->firstOrFail();

        $this->authorize('grantRole', [$workspace, $role]);

        $inviteToWorkspace->handle(
            $workspace,
            $request->user(),
            $validated['email'],
            $role,
            $validated['channel_ids'] ?? [],
        );

        return back()->with('status', __('flashes.invitation.sent', ['email' => $validated['email']]));
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
            $invitation->workspaceRole,
            $invitation->channels()->pluck('channels.id'),
        );

        return back()->with('status', __('flashes.invitation.resent', ['email' => $invitation->email]));
    }

    public function destroy(Workspace $workspace, Invitation $invitation): RedirectResponse
    {
        $this->authorize('invite', $workspace);

        abort_unless($invitation->workspace_id === $workspace->id, 404);

        $invitation->delete();

        return back()->with('status', __('flashes.invitation.withdrawn', ['email' => $invitation->email]));
    }

    /**
     * Whether the role that was asked for is one for people from outside.
     *
     * Guarded on the value being a number at all: this runs while the rules are
     * being built, so before 'role' has been held to being an integer. A word
     * arriving here would otherwise be handed to the database as a key and take
     * the request down with a type error, where a validation message was the
     * answer that was wanted.
     */
    private function rolePointsOutside(mixed $role, Workspace $workspace): bool
    {
        if (! is_numeric($role)) {
            return false;
        }

        return (bool) Role::query()
            ->whereKey((int) $role)
            ->where('workspace_id', $workspace->id)
            ->value('is_external');
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
                $fail(__('requests.invite.already_member'));
            }
        };
    }
}
