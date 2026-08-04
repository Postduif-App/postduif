<?php

namespace App\Actions\Board;

use App\Models\BoardComment;
use App\Models\BoardPost;
use App\Models\User;
use App\Models\Workspace;

/**
 * Writing on the prikbord, and everything that happens to a notice afterwards.
 *
 * One action rather than five, unlike the ticket side of the application. A
 * ticket has a lifecycle — statuses, events, announcements into a channel — and
 * each move is a thing in its own right. A notice has none of that: it goes up,
 * it may be corrected, it may be pinned, it comes down. Splitting four
 * one-liners across four files would be more places to look for less.
 */
class PostToBoard
{
    public function handle(Workspace $workspace, User $author, string $title, string $body): BoardPost
    {
        return BoardPost::create([
            'workspace_id' => $workspace->id,
            'user_id' => $author->id,
            'title' => $title,
            'body' => $body,
        ]);
    }

    /**
     * Correcting a notice, marked as corrected.
     *
     * The stamp is not optional and not a parameter: a board is read by people
     * who were not there when it went up, and one where the text can change
     * without a trace is one that cannot be quoted back at anybody.
     */
    public function edit(BoardPost $post, string $title, string $body): BoardPost
    {
        $post->forceFill([
            'title' => $title,
            'body' => $body,
            'edited_at' => now(),
        ])->save();

        return $post;
    }

    /**
     * Move a notice to the top of the board, or let it fall back among the rest.
     *
     * One method for both directions rather than pin() and unpin(). Pinned is a
     * state the caller sets, not a transition it requests — two methods would
     * let the browser ask for a change that has already happened, and the answer
     * to "pin this already-pinned notice" would have to be either a lie or an
     * error.
     */
    public function pin(BoardPost $post, bool $pinned): BoardPost
    {
        $post->forceFill(['pinned_at' => $pinned ? now() : null])->save();

        return $post;
    }

    /** Take it down. Soft deleted, so taking it down stays undoable. */
    public function withdraw(BoardPost $post): void
    {
        $post->delete();
    }

    public function comment(BoardPost $post, User $author, string $body): BoardComment
    {
        return BoardComment::create([
            'board_post_id' => $post->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);
    }

    /** The same stamp as a notice, for the same reason. */
    public function editComment(BoardComment $comment, string $body): BoardComment
    {
        $comment->forceFill(['body' => $body, 'edited_at' => now()])->save();

        return $comment;
    }

    public function withdrawComment(BoardComment $comment): void
    {
        $comment->delete();
    }
}
