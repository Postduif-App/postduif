<?php

namespace App\Actions\Workspace;

use App\Enums\SystemRole;
use App\Models\InviteLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateInviteLink
{
    public function __construct(private ResolveInvitableChannels $resolveChannels) {}

    /**
     * Make a link anybody holding it can use to get in.
     *
     * Always a new row, never an update of an existing one — the opposite of
     * InviteToWorkspace, which rewrites the invitation for an address and so
     * kills the previous link. Here the previous link may well be in somebody's
     * mail already, and quietly breaking it because a second one was made is
     * not something making a link should do. Withdrawing is its own action.
     *
     * @param  int|null  $maxUses  Null for as often as anybody likes.
     * @param  int|null  $validForDays  Null to keep working until it is withdrawn.
     * @param  Collection<int, int>|array<int, int>  $channelIds  The channels it
     *                                                            drops somebody into. Required for a guest, who has no workspace
     *                                                            to browse; optional for a member, for whom it is a shortcut.
     */
    public function handle(
        Workspace $workspace,
        User $creator,
        SystemRole $role,
        ?int $maxUses = null,
        ?int $validForDays = null,
        Collection|array $channelIds = [],
    ): InviteLink {
        return DB::transaction(function () use ($workspace, $creator, $role, $maxUses, $validForDays, $channelIds): InviteLink {
            $link = InviteLink::create([
                'workspace_id' => $workspace->id,
                'created_by' => $creator->id,
                'token' => InviteLink::freshToken(),
                'role' => $role,
                'max_uses' => $maxUses,
                'expires_at' => $validForDays === null ? null : now()->addDays($validForDays),
            ]);

            $link->channels()->sync($this->resolveChannels->handle($workspace, $channelIds));

            return $link;
        });
    }
}
