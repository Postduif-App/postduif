<?php

namespace App\Actions\Workspace;

use App\Enums\SystemRole;
use App\Mail\WorkspaceInvitationMail;
use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InviteToWorkspace
{
    public function __construct(private ResolveInvitableChannels $resolveChannels) {}

    /**
     * Invite an e-mail address into a workspace, and mail them the link.
     *
     * One invitation per address per workspace — the table says so — which
     * makes re-inviting the same thing as resending: the row is rewritten with
     * a new token and a new expiry, and the old link stops working. That is
     * what you want when a mail went to the wrong person or was never read.
     *
     * @param  Collection<int, int>|array<int, int>  $channelIds  Only meaningful
     *                                                            for guests, who have no workspace to browse and so have to be
     *                                                            handed their channels.
     */
    public function handle(
        Workspace $workspace,
        User $inviter,
        string $email,
        SystemRole $role,
        Collection|array $channelIds = [],
    ): Invitation {
        $email = mb_strtolower(trim($email));

        $invitation = DB::transaction(function () use ($workspace, $inviter, $email, $role, $channelIds): Invitation {
            $invitation = Invitation::updateOrCreate(
                ['workspace_id' => $workspace->id, 'email' => $email],
                [
                    'invited_by' => $inviter->id,
                    'role' => $role,
                    'token' => Invitation::freshToken(),
                    'expires_at' => now()->addDays(Invitation::VALID_FOR_DAYS),
                ],
            );

            // An invitation that was already accepted and is now being sent
            // again is a fresh one; leaving the timestamp would make the new
            // token unusable the moment it arrives.
            $invitation->forceFill(['accepted_at' => null])->save();

            $invitation->channels()->sync($this->resolveChannels->handle($workspace, $channelIds));

            return $invitation;
        });

        Mail::to($email)->send(new WorkspaceInvitationMail($invitation));

        return $invitation;
    }
}
