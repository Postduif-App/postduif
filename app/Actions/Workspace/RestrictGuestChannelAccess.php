<?php

namespace App\Actions\Workspace;

use App\Enums\ChannelType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RestrictGuestChannelAccess
{
    /**
     * Bring somebody's channel memberships in line with having become a guest.
     *
     * The rule a guest lives under is "only the channels you were put in", but
     * Channel::scopeVisibleTo() grants access on membership alone — it does not
     * ask what role the member holds, and it should not: a private channel is
     * open to its members whoever they are. So a member who joined every public
     * channel and is then made a guest would keep all of them, and the
     * demotion would have changed nothing.
     *
     * The choice made here: public channel memberships are dropped, private
     * channels and DMs are kept.
     *
     * Public ones are the ones they let themselves into — nobody decided they
     * belonged there, and under the new role nobody would have. A private
     * channel is the opposite: somebody added them on purpose, and that is
     * exactly the kind of access a guest is meant to have. A DM is a
     * conversation between two people and is not the workspace's to end.
     *
     * Channels they created are left alone too. The creator cannot be removed
     * from their own channel anywhere else in the app either, because a channel
     * with an absent creator is one whose membership nobody can change.
     *
     * @return int the number of channels the member was removed from
     */
    public function handle(Workspace $workspace, User $member): int
    {
        return DB::transaction(function () use ($workspace, $member): int {
            $toLeave = $workspace->channels()
                ->where('type', ChannelType::Public)
                ->where(fn ($query) => $query->whereNull('created_by')->orWhere('created_by', '!=', $member->id))
                ->whereHas('members', fn ($members) => $members->whereKey($member->id))
                ->pluck('id');

            if ($toLeave->isEmpty()) {
                return 0;
            }

            DB::table('channel_user')
                ->whereIn('channel_id', $toLeave)
                ->where('user_id', $member->id)
                ->delete();

            return $toLeave->count();
        });
    }
}
