<?php

namespace App\Http\Controllers;

use App\Actions\Board\PostToBoard;
use App\Http\Requests\StoreBoardCommentRequest;
use App\Models\BoardComment;
use App\Models\BoardPost;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Replies under a notice.
 *
 * Its own controller rather than three more methods on BoardPostController, for
 * the same reason TicketCommentController is separate: a reply has its own
 * abilities — editing is the author's alone, withdrawal is not — and a
 * controller that holds both sets is one where the wrong authorize() call is a
 * copy-paste away.
 */
class BoardCommentController extends Controller
{
    public function __construct(
        private readonly PostToBoard $postToBoard,
    ) {}

    public function store(StoreBoardCommentRequest $request, Workspace $workspace, BoardPost $boardPost): RedirectResponse
    {
        $this->postToBoard->comment(
            $boardPost,
            $request->user(),
            $request->string('body')->toString(),
        );

        /*
         * Back to the notice being read rather than to the top of the board:
         * somebody who just replied is still in the middle of it.
         *
         * Including how they were reading it. This redirect decides the URL the
         * browser lands on, so a `full` that is not repeated here is a
         * fullscreen notice that folds itself back into a panel the moment you
         * answer it — which is exactly when you are least done reading.
         */
        return to_route('chat.board.index', array_filter([
            'workspace' => $workspace,
            'post' => $boardPost->id,
            'full' => $request->boolean('full') ? 1 : null,
        ]));
    }

    public function update(Request $request, Workspace $workspace, BoardPost $boardPost, BoardComment $comment): RedirectResponse
    {
        $this->authorize('update', $comment);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $this->postToBoard->editComment($comment, $validated['body']);

        return back();
    }

    public function destroy(Workspace $workspace, BoardPost $boardPost, BoardComment $comment): RedirectResponse
    {
        $this->authorize('delete', $comment);

        $this->postToBoard->withdrawComment($comment);

        return back();
    }
}
