<?php

namespace App\Events;

use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody put themselves on the clock, or took themselves off it.
 *
 * Not broadcast, like ChannelMemberJoined and for the same reason: nothing on a
 * screen is waiting for it. It exists so a workflow can hang off clocking
 * without ClockIn and ClockOut having to know that workflows are a thing.
 *
 * One event for both directions rather than two. They carry exactly the same
 * cargo — a member, a shift — and which way it went is a property of the punch,
 * which is also how somebody writing a workflow thinks about it: "when iemand
 * klokt", and then in or out.
 */
class ClockPunched
{
    use Dispatchable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly User $user,
        public readonly TimeEntry $entry,
        /** Which way it went: 'in' when a shift began, 'out' when one ended. */
        public readonly string $direction,
    ) {}
}
