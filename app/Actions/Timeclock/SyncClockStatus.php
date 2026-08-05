<?php

namespace App\Actions\Timeclock;

use App\Actions\Users\SetStatus;
use App\Enums\Availability;
use App\Models\User;

/**
 * Letting the clock move somebody's availability along with it.
 *
 * Only for members who asked for it — see the clock_sets_status column, which
 * is off until somebody turns it on. What it does is narrow on purpose: it
 * moves availability and leaves the emoji and the words alone. "Op kantoor" is
 * something a person wrote about themselves and no clock knows enough to
 * rewrite it.
 *
 * The change is recorded as a manual one, which is what makes the schedule the
 * floor rather than the ceiling. Clocking in at ten wins over the rule that
 * covers the morning, and the evening rule still takes over at five — see
 * ApplyStatusRules, which decides that by comparing windows rather than by
 * anything expiring.
 */
class SyncClockStatus
{
    public function __construct(private readonly SetStatus $setStatus) {}

    public function clockedIn(User $user): void
    {
        $this->apply($user, Availability::Available);
    }

    /**
     * Away rather than "do not disturb" at the end of a shift.
     *
     * Away says where somebody is; do-not-disturb asks for silence and stops
     * notifications from leaving the building — see Availability. Clocking out
     * for the day should not quietly turn off the message that arrives at
     * seven; it should say that you are not at your desk, which is exactly
     * what happened.
     */
    public function clockedOut(User $user): void
    {
        $this->apply($user, Availability::Away);
    }

    private function apply(User $user, Availability $availability): void
    {
        if (! $user->clock_sets_status) {
            return;
        }

        if ($user->availability === $availability) {
            return;
        }

        $this->setStatus->handle(
            $user,
            $user->status_emoji,
            $user->status_text,
            $availability,
        );
    }
}
