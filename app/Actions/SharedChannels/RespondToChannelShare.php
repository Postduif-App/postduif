<?php

namespace App\Actions\SharedChannels;

use App\Events\ChannelShareAnswered;
use App\Features\SharedChannels;
use App\Models\ChannelShare;
use App\Models\User;
use RuntimeException;

class RespondToChannelShare
{
    /**
     * The invited workspace's answer.
     *
     * Accepting grants nothing on its own either: it opens the door, and the
     * people who walk through it are added afterwards, one by one, by whoever
     * runs the invited workspace. A yes that swept the whole workspace into
     * somebody else's channel would be a single click with a blast radius
     * nobody could see beforehand.
     *
     * @throws RuntimeException when the offer is no longer open, or the
     *                          workspace has since switched the feature off
     */
    public function handle(ChannelShare $share, User $user, bool $accepted): ChannelShare
    {
        if (! $share->isPending()) {
            throw new RuntimeException('This share has already been answered.');
        }

        /*
         * Asked again here and not only when the offer was made. A beheerder
         * can switch the feature off between the invitation and the answer, and
         * "it was on when they asked" is not a reason to let a workspace be
         * joined to another one now.
         */
        if (! $share->workspace->hasFeature(SharedChannels::class)) {
            throw new RuntimeException('This workspace does not accept shared channels.');
        }

        $share->forceFill($accepted
            ? ['accepted_at' => now(), 'accepted_by' => $user->id]
            : ['declined_at' => now()])->save();

        // The host is the one waiting on this, and nothing else tells them.
        ChannelShareAnswered::dispatch($share->id, $accepted);

        return $share;
    }
}
