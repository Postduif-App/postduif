<?php

namespace App\Policies;

use App\Models\Transfer;
use App\Models\User;

/**
 * Who gets to look at a transfer from the inside, and who gets to stop it.
 *
 * Nothing here is about the recipient. They hold a token, which is a different
 * kind of proof entirely and is checked on the public route — these questions
 * are about the people in the workspace the files left from.
 */
class TransferPolicy
{
    /**
     * Seeing what it holds and how often it has been fetched.
     *
     * The sender, or whoever runs the workspace. Not every member: a transfer
     * often carries something meant for one customer, and the list of what
     * colleagues are sending out is not a workspace-wide noticeboard.
     */
    public function view(User $user, Transfer $transfer): bool
    {
        if ($transfer->created_by === $user->id) {
            return true;
        }

        return $user->can('manage', $transfer->workspace);
    }

    /**
     * Withdrawing it.
     *
     * The same two people, and deliberately so: a file sent to the wrong
     * address has to be stoppable by somebody who is still around, which the
     * sender on holiday is not. That is the whole reason an admin is here — not
     * to police what colleagues send, but so a mistake has a second person who
     * can undo it.
     */
    public function delete(User $user, Transfer $transfer): bool
    {
        return $this->view($user, $transfer);
    }
}
