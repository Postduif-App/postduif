<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Workspace\RemoveWorkspaceMember;
use App\Actions\Workspace\RestrictGuestChannelAccess;
use App\Actions\Workspace\SyncGuestChannels;
use App\Concerns\ResolvesCurrentWorkspace;
use App\Enums\ChannelType;
use App\Enums\WorkspaceAbility;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceMemberController extends Controller
{
    use ResolvesCurrentWorkspace;

    /**
     * Everyone in the workspace, and what may be done to them.
     */
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        $viewer = $request->user();

        $guestChannelIds = $this->guestChannelIds($workspace);

        // Where each role sits in this workspace's own order, so the list can
        // be sorted by standing without asking the database once per member.
        $rolePositions = $workspace->roles()->pluck('position', 'id')->all();

        return Inertia::render('settings/members', [
            'workspaceName' => $workspace->name,
            'channelOptions' => $workspace->channels()
                ->where('type', '!=', ChannelType::Direct)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get()
                ->map(fn (Channel $channel): array => [
                    'id' => $channel->id,
                    'name' => (string) $channel->name,
                    'type' => $channel->type->value,
                ])->all(),
            'members' => $workspace->members()
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'username' => $member->username,
                    'role' => $member->membership->workspace_role_id,
                    'roleLabel' => $workspace->roleFor($member)?->name,
                    /*
                     * What the badge is drawing, which an id cannot say. Two
                     * facts rather than a name: whether this role runs the
                     * place, and whether it is somebody from outside — the two
                     * things the colour has always meant.
                     */
                    'roleManages' => $workspace->allows($member, WorkspaceAbility::ManageWorkspace),
                    'roleIsExternal' => $workspace->isExternal($member),
                    'joinedAt' => $member->membership->joined_at,
                    // What somebody is up to, as a column of its own. Managing
                    // members means knowing who is around to be handed
                    // something, and that is not derivable from a role.
                    'statusEmoji' => $member->status_emoji,
                    'statusText' => $member->status_text,
                    'availability' => $member->availability->value,
                    // Only guests carry this: for everyone else the answer is
                    // "the whole workspace", and a list of channels next to
                    // their name would suggest a limit that is not there.
                    'channelIds' => $workspace->isExternal($member)
                        ? ($guestChannelIds[$member->id] ?? [])
                        : null,
                    // Worked out here rather than in the browser: the rules
                    // about owners and about editing your own row live in one
                    // place, and the interface only renders the answer.
                    'canChangeRole' => $viewer->can('updateMemberRole', [$workspace, $member]),
                    'canRemove' => $viewer->can('removeMember', [$workspace, $member]),
                    'canManageChannels' => $viewer->can('manageGuestChannels', [$workspace, $member]),
                ])
                /*
                 * Sorted by standing rather than alphabetically: whoever runs
                 * the workspace is who you are looking for when something needs
                 * changing, and people from outside sort to the bottom.
                 *
                 * By the role's own position. A workspace decides the order of
                 * its roles, so a ranking compiled in here would be this
                 * application's opinion about a list it does not own.
                 */
                ->sortBy(fn (array $member) => [
                    $rolePositions[$member['role']] ?? PHP_INT_MAX,
                    mb_strtolower($member['name']),
                ])
                ->values()
                ->all(),
            /*
             * Only the roles this member could actually hand out. The policy
             * refuses the rest anyway; leaving them in the dropdown would be
             * offering a choice that answers 403.
             */
            'roleOptions' => $workspace->roles()
                ->get()
                ->filter(fn (Role $role): bool => $viewer->can('grantRole', [$workspace, $role]))
                ->map(fn (Role $role): array => [
                    'value' => $role->id,
                    'label' => $role->name,
                ])->values()->all(),
        ]);
    }

    public function update(
        Request $request,
        User $user,
        RestrictGuestChannelAccess $restrictChannelAccess,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);

        $this->authorize('updateMemberRole', [$workspace, $user]);

        $validated = $request->validate([
            /*
             * A role of this workspace, by its own id. Scoped in the rule
             * rather than checked afterwards: without the workspace_id an id
             * from somewhere else would name a role that exists, and the only
             * thing standing between that and a promotion would be the policy
             * below.
             */
            'role' => [
                'required',
                // Before the lookup: without it a word arrives at a bigint
                // column and Postgres, not the validator, is what refuses it.
                'integer',
                Rule::exists('workspace_roles', 'id')->where('workspace_id', $workspace->id),
            ],
        ]);

        $role = $workspace->roles()->findOrFail($validated['role']);

        $this->authorize('grantRole', [$workspace, $role]);
        $this->guardTheLastManager($workspace, $user, $role);

        $workspace->members()->updateExistingPivot($user->id, [
            'workspace_role_id' => $role->id,
        ]);

        // Moving somebody outside has to reach the channels too, or the change
        // touches the badge and nothing else — see the action for why public
        // channels go and the rest stays.
        $dropped = $role->is_external
            ? $restrictChannelAccess->handle($workspace, $user)
            : 0;

        /*
         * One sentence per branch, chosen by how many channels went. Built by
         * hand this was a role sentence with a second one glued on, which is
         * still followable in Dutch and not buildable at all in a language that
         * puts the parts in another order.
         */
        return back()->with('status', trans_choice('flashes.member.role_changed', $dropped, [
            'name' => $user->name,
            'role' => mb_strtolower($role->name),
        ]));
    }

    public function destroy(
        Request $request,
        User $user,
        RemoveWorkspaceMember $removeMember,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);

        $this->authorize('removeMember', [$workspace, $user]);

        $removeMember->handle($workspace, $user);

        return back()->with('status', __('flashes.member.removed', ['name' => $user->name]));
    }

    /**
     * Set exactly which channels a guest belongs to.
     *
     * Its own route rather than part of update(): changing somebody's role and
     * changing where they may read are separate decisions, and folding them
     * into one request would make a role dropdown able to empty a guest's
     * sidebar by leaving a field out.
     */
    public function updateChannels(
        Request $request,
        User $user,
        SyncGuestChannels $syncGuestChannels,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);

        $this->authorize('manageGuestChannels', [$workspace, $user]);

        // An absent field means "in no channels": the form always carries the
        // whole list, so there is no partial update this could be mistaken for.
        $validated = $request->validate([
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['integer'],
        ]);

        ['added' => $added, 'removed' => $removed] = $syncGuestChannels
            ->handle($workspace, $user, $validated['channel_ids'] ?? []);

        return back()->with('status', $added === 0 && $removed === 0
            ? __('flashes.member.channels_unchanged', ['name' => $user->name])
            : __('flashes.member.channels_updated', ['name' => $user->name]));
    }

    /**
     * Every guest's channel ids, keyed by user id.
     *
     * One query for the whole list rather than one per row: the member list is
     * the page most likely to grow, and a per-guest lookup here is the kind of
     * N+1 that only shows up once a workspace is large enough to notice.
     *
     * @return array<int, array<int, int>>
     */
    private function guestChannelIds(Workspace $workspace): array
    {
        $external = $workspace->roles()->where('is_external', true)->pluck('id');

        $guestIds = $workspace->members()
            ->wherePivotIn('workspace_role_id', $external->all())
            ->pluck('users.id');

        if ($guestIds->isEmpty()) {
            return [];
        }

        return DB::table('channel_user')
            ->join('channels', 'channels.id', '=', 'channel_user.channel_id')
            ->where('channels.workspace_id', $workspace->id)
            ->where('channels.type', '!=', ChannelType::Direct->value)
            ->whereNull('channels.archived_at')
            ->whereIn('channel_user.user_id', $guestIds)
            ->get(['channel_user.user_id', 'channel_user.channel_id'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('channel_id')->map(fn ($id): int => (int) $id)->all())
            ->all();
    }

    /**
     * A workspace nobody can manage has no way back.
     *
     * Asked of the right rather than of a role called "eigenaar", which is what
     * it was before: a workspace may now have three roles that can manage it
     * and none of them named that, and it may take the right away from the one
     * that was. What has to survive is that somebody, in some role, can still
     * hand out roles and settle a dispute.
     */
    private function guardTheLastManager(Workspace $workspace, User $target, Role $role): void
    {
        if ($role->allows(WorkspaceAbility::ManageWorkspace)) {
            return;
        }

        $managing = $workspace->roles()
            ->get()
            ->filter(fn (Role $role): bool => $role->allows(WorkspaceAbility::ManageWorkspace))
            ->pluck('id');

        $remaining = $workspace->members()
            ->wherePivotIn('workspace_role_id', $managing->all())
            ->where('users.id', '!=', $target->id)
            ->count();

        if ($remaining === 0) {
            throw ValidationException::withMessages([
                'role' => __('requests.member.last_owner'),
            ]);
        }
    }
}
