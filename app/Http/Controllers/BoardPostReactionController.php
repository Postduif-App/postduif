<?php

namespace App\Http\Controllers;

use App\Actions\Board\ToggleBoardReaction;
use App\Http\Requests\StoreBoardReactionRequest;
use App\Models\BoardPost;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Emoji under a notice.
 *
 * Its own controller rather than another method on BoardPostController, the same
 * split BoardCommentController makes: reacting has its own ability, and a
 * controller holding both sets is one where the wrong authorize() call is a
 * copy-paste away. The ability is asked in the form request, before the method
 * below runs at all.
 */
class BoardPostReactionController extends Controller
{
    public function store(
        StoreBoardReactionRequest $request,
        Workspace $workspace,
        BoardPost $boardPost,
        ToggleBoardReaction $toggleBoardReaction,
    ): RedirectResponse {
        $toggleBoardReaction->handle(
            post: $boardPost,
            user: $request->user(),
            emoji: $request->string('emoji')->value(),
        );

        // back() rather than a route of its own, so whatever the reader had open
        // stays open — the notice, and whether it filled the screen.
        return back();
    }
}
