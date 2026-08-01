<?php

namespace App\Policies;

use App\Models\User;

/**
 * Only used by the admin panel: the chat app never authorizes against a user.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Handing out or taking away moderation rights, never your own: a moderator
     * who locks themselves out of the panel cannot undo it from inside.
     */
    public function toggleAdmin(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    /**
     * Barring someone from the platform, or letting them back in. Never
     * yourself: a moderator who suspends their own account loses the panel that
     * holds the only way to undo it.
     */
    public function toggleSuspended(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    /**
     * Deleting a user takes their messages and memberships with them, which is
     * not a moderation decision — toggleSuspended() is.
     */
    public function delete(User $user, User $target): bool
    {
        return false;
    }
}
