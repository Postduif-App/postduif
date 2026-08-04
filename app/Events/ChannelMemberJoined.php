<?php

namespace App\Events;

use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody became a member of a channel.
 *
 * Not broadcast, unlike most of the events here: nothing on a screen is waiting
 * for it. It exists so that a workflow can be hung off joining without every
 * place that adds a member having to know that workflows exist.
 *
 * Deliberately not dispatched from every place a row lands in the pivot. The
 * two onboarding paths — accepting an invitation, redeeming an invite link —
 * put a new guest into everything they were given at once, and firing a welcome
 * five times in the same second is not a welcome. Making the channel is not a
 * join either: the person who opened it was already there.
 */
class ChannelMemberJoined
{
    use Dispatchable;

    public function __construct(
        public readonly Channel $channel,
        public readonly User $user,
    ) {}
}
