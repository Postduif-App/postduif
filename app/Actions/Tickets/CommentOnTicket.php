<?php

namespace App\Actions\Tickets;

use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CommentOnTicket
{
    /**
     * Say something on a ticket.
     *
     * The first answer from anyone other than the person who raised it stamps
     * first_responded_at. Nothing reads that column yet; it is filled from the
     * start because it cannot be worked out afterwards, and it is the one number
     * that says whether a customer channel is actually being served.
     */
    public function handle(Ticket $ticket, User $author, string $body): TicketComment
    {
        return DB::transaction(function () use ($ticket, $author, $body): TicketComment {
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            if ($ticket->first_responded_at === null && $ticket->opened_by !== $author->id) {
                $ticket->forceFill(['first_responded_at' => $comment->created_at])->save();
            }

            TicketUpdated::dispatch($ticket);

            return $comment;
        });
    }

    /**
     * Rewrite one's own comment, marked as edited.
     *
     * Same rule as a message: the mark is not optional, because a support
     * history where words can change without a trace is worth nothing to
     * whoever has to reconstruct what was agreed.
     */
    public function edit(TicketComment $comment, string $body): TicketComment
    {
        $comment->forceFill(['body' => $body, 'edited_at' => now()])->save();

        TicketUpdated::dispatch($comment->ticket);

        return $comment;
    }

    /**
     * Withdraw a comment. Soft deleted, so the timeline keeps its place: a
     * support history where a line can vanish without a trace is one neither
     * side can rely on.
     */
    public function withdraw(TicketComment $comment): void
    {
        $comment->delete();

        TicketUpdated::dispatch($comment->ticket);
    }
}
