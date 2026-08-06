<?php

namespace App\Http\Controllers;

use App\Actions\Workspace\CreateInviteLink;
use App\Models\InviteLink;
use App\Models\Role;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Links that let anybody holding them into the workspace.
 *
 * The counterpart of WorkspaceInvitationController, which invites one named
 * address. A link names nobody, so what keeps it from being a permanent open
 * door are the limits on it: how often it may be used and until when. Both are
 * optional, because a link you hand out inside your own company and withdraw
 * when you are done is a perfectly reasonable thing to want.
 */
class InviteLinkController extends Controller
{
    /** A year: past that, "valid until" stops meaning anything. */
    private const MAX_VALID_DAYS = 365;

    public function store(
        Request $request,
        Workspace $workspace,
        CreateInviteLink $createInviteLink,
    ): RedirectResponse {
        $this->authorize('invite', $workspace);

        $validated = $request->validate([
            /*
             * A role of this workspace, by its own id. Scoped in the rule
             * rather than checked afterwards: without the workspace_id an id
             * from somewhere else would name a role that exists, and the only
             * thing standing between that and a link into the wrong workspace
             * is the policy below.
             */
            'role' => [
                'required',
                'integer',
                Rule::exists('workspace_roles', 'id')->where('workspace_id', $workspace->id),
            ],

            /*
             * Somebody from outside sees nothing but the channels named here,
             * so a link without any would drop them into a workspace with
             * nothing in it. Asked of the role rather than of a name: a
             * workspace may call its outside role anything.
             */
            'channel_ids' => [
                'array',
                'max:50',
                Rule::requiredIf(fn (): bool => Role::query()
                    ->whereKey($request->input('role'))
                    ->where('workspace_id', $workspace->id)
                    ->value('is_external') ?? false),
            ],
            'channel_ids.*' => ['integer'],

            // Absent means unbounded, in both cases. Nullable rather than
            // required so the browser can leave the field empty and mean it.
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'valid_for_days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_VALID_DAYS],
        ], [
            'channel_ids.required' => __('requests.invite.channels_required'),
        ]);

        $role = $workspace->roles()->whereKey($validated['role'])->firstOrFail();

        $this->authorize('grantRole', [$workspace, $role]);

        $createInviteLink->handle(
            $workspace,
            $request->user(),
            $role,
            $validated['max_uses'] ?? null,
            $validated['valid_for_days'] ?? null,
            $validated['channel_ids'] ?? [],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.invitation.link_created')]);

        return back();
    }

    /**
     * Withdraw it.
     *
     * Marked rather than deleted. The link is out there — in a mail, in a chat,
     * on a page — and somebody who follows it deserves to be told it was
     * withdrawn rather than to land on a page that says the link never existed.
     * The row is also the record of how often it was used.
     */
    public function destroy(Workspace $workspace, InviteLink $inviteLink): RedirectResponse
    {
        $this->authorize('invite', $workspace);

        abort_unless($inviteLink->workspace_id === $workspace->id, 404);

        // Not touched again if it was already withdrawn: the moment it stopped
        // working is the interesting one, and a second click should not move it.
        if (! $inviteLink->isRevoked()) {
            $inviteLink->forceFill(['revoked_at' => now()])->save();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('flashes.invitation.link_withdrawn')]);

        return back();
    }
}
