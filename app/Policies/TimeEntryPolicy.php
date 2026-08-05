<?php

namespace App\Policies;

use App\Models\TimeEntry;
use App\Models\User;

/**
 * Who may touch a recorded stretch of time.
 *
 * Only the person it is about, and that includes a workspace manager and a
 * platform moderator — neither of whom gets an exception here. Somebody else
 * changing what your hours say is not an administrative convenience, it is a
 * record of your working day being rewritten by a third party. Whether a
 * colleague may *read* them is a different question with a different answer:
 * see WorkspacePolicy::seeHours.
 *
 * A correction to a stretch that was never worked is what delete() is for; that
 * is why this feature has one at all.
 */
class TimeEntryPolicy
{
    public function view(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id;
    }

    /**
     * Adjusting the times afterwards.
     *
     * A running shift included: "ik was er al om acht uur" is the correction
     * people most often need, and making them clock out first to fix the start
     * would be asking them to record a second thing that is not true.
     */
    public function update(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id;
    }

    public function delete(User $user, TimeEntry $entry): bool
    {
        return $entry->user_id === $user->id;
    }
}
