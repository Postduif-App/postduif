<?php

namespace App\Actions\Workspace;

use App\Models\InviteLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RedeemInviteLink
{
    /**
     * Use a link: join the workspace it belongs to, and the channels on it.
     *
     * The twin of AcceptInvitation, with one thing a mailed invitation never
     * has to worry about — a link is held by more than one person at a time.
     * So the row is locked and its usability re-checked inside the transaction:
     * without that, two people opening a link with one use left both read
     * "uses 0 of 1", and both get in.
     *
     * Returns false when the link turned out to be spent after all. The caller
     * shows the same page it would have shown before, which is the honest
     * answer: by the time they clicked, it was gone.
     */
    public function handle(InviteLink $inviteLink, User $user): bool
    {
        return DB::transaction(function () use ($inviteLink, $user): bool {
            $link = InviteLink::whereKey($inviteLink->id)->lockForUpdate()->first();

            // Gone between being read and being locked: somebody withdrew it,
            // or the workspace itself went. Same answer either way.
            if (! $link instanceof InviteLink || ! $link->isUsable()) {
                return false;
            }

            $workspace = $link->workspace;

            /*
             * Somebody who is already in keeps the standing they have — a link
             * is a way in, not a way to be demoted to guest — and does not
             * spend a use. Otherwise a colleague who opens the same link twice
             * eats somebody else's place.
             */
            $isNew = ! $workspace->hasMember($user);

            if ($isNew) {
                $workspace->members()->attach($user->id, [
                    'workspace_role_id' => $link->workspace_role_id,
                    'joined_at' => now(),
                ]);
            }

            /*
             * The channels are added either way. Following a link to land in
             * the two channels it names is a reasonable thing for an existing
             * member to do, and it costs nobody anything.
             */
            $alreadyIn = $user->channels()->pluck('channels.id');

            $channels = $link->channels()
                ->whereNotIn('channels.id', $alreadyIn)
                ->get();

            foreach ($channels as $channel) {
                $channel->members()->attach($user->id, ['joined_at' => now()]);
            }

            if ($isNew) {
                $link->increment('uses');
            }

            return true;
        });
    }
}
