<?php

namespace App\Actions\Workspace;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    /**
     * Put an invited user into the workspace, and into the channels they were
     * invited to.
     *
     * Deliberately takes an existing User: whether that account was just
     * created from the registration form or was already signed in is the
     * controller's problem, not this one's. Both cases end here.
     *
     * One transaction for the lot. A guest who landed in the workspace but in
     * none of their channels would be looking at an empty sidebar with no way
     * to fix it — there is nothing they are allowed to browse to.
     */
    public function handle(Invitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
            $workspace = $invitation->workspace;

            // An existing member keeps the standing they already have: an
            // invitation is a way in, not a way to be demoted to guest.
            if (! $workspace->hasMember($user)) {
                $workspace->members()->attach($user->id, [
                    'role' => $invitation->role->value,
                    'joined_at' => now(),
                ]);
            }

            $alreadyIn = $user->channels()->pluck('channels.id');

            $channels = $invitation->channels()
                ->whereNotIn('channels.id', $alreadyIn)
                ->get();

            foreach ($channels as $channel) {
                $channel->members()->attach($user->id, ['joined_at' => now()]);
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
        });
    }
}
