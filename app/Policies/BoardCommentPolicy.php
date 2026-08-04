<?php

namespace App\Policies;

use App\Models\BoardComment;
use App\Models\User;

class BoardCommentPolicy
{
    /**
     * Rewriting one's own reply, and nobody else's.
     *
     * The same line MessagePolicy and TicketCommentPolicy draw, and it is worth
     * restating why: a withdrawal is visible as a withdrawal, while an edit is
     * indistinguishable from what somebody typed in the first place. Running the
     * workspace buys you the right to take a remark off the board — see delete()
     * — not the right to put different words in somebody's mouth.
     */
    public function update(User $user, BoardComment $comment): bool
    {
        if ($comment->deleted_at !== null) {
            return false;
        }

        return $comment->user_id === $user->id
            && $user->can('comment', $comment->post);
    }

    /**
     * Withdrawing a reply — one's own, or anybody's if you run the workspace.
     *
     * Wider than update() on purpose. The board is the one place in this
     * application where everybody in the workspace writes into the same list,
     * and a list nobody can tidy is a list that eventually has to be ignored.
     */
    public function delete(User $user, BoardComment $comment): bool
    {
        if (! $user->can('view', $comment->post)) {
            return false;
        }

        return $comment->user_id === $user->id
            || app(WorkspacePolicy::class)->manage($user, $comment->post->workspace);
    }
}
