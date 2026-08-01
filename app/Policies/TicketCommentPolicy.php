<?php

namespace App\Policies;

use App\Models\TicketComment;
use App\Models\User;

class TicketCommentPolicy
{
    /**
     * Rewriting a comment, which only its author may do.
     *
     * The same line MessagePolicy draws: removal is visible as removal, while an
     * edit is indistinguishable from what somebody typed, so nobody gets to put
     * different words in another person's mouth. A withdrawn comment is a
     * tombstone with no text left to change.
     */
    public function update(User $user, TicketComment $comment): bool
    {
        if ($comment->isDeleted()) {
            return false;
        }

        return $comment->user_id === $user->id
            && $user->can('comment', $comment->ticket);
    }

    /**
     * The author, or a platform moderator.
     *
     * Deliberately not the person who manages the channel's tickets: being able
     * to set a status is not the same as being able to take somebody's words out
     * of a support history that both sides rely on.
     */
    public function delete(User $user, TicketComment $comment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $comment->user_id === $user->id
            && $user->can('comment', $comment->ticket);
    }
}
