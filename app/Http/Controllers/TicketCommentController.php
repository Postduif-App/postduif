<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\CommentOnTicket;
use App\Http\Requests\StoreTicketCommentRequest;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TicketCommentController extends Controller
{
    public function store(
        StoreTicketCommentRequest $request,
        Workspace $workspace,
        Channel $channel,
        Ticket $ticket,
        CommentOnTicket $commentOnTicket,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $commentOnTicket->handle(
            ticket: $ticket,
            author: $request->user(),
            body: $request->string('body')->trim()->value(),
        );

        return back();
    }

    public function update(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Ticket $ticket,
        TicketComment $comment,
        CommentOnTicket $commentOnTicket,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('update', $comment);

        $body = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ])['body'];

        $commentOnTicket->edit($comment, trim($body));

        return back();
    }

    /**
     * Withdrawing a comment. Soft deleted, so the timeline keeps its place: a
     * support history where a line can vanish without a trace is one neither
     * side can rely on.
     */
    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Ticket $ticket,
        TicketComment $comment,
        CommentOnTicket $commentOnTicket,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        $this->authorize('delete', $comment);

        $commentOnTicket->withdraw($comment);

        return back();
    }
}
