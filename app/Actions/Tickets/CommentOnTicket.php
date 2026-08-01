<?php

namespace App\Actions\Tickets;

use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
    /**
     * @param  array<int, UploadedFile>  $attachments
     */
    public function handle(
        Ticket $ticket,
        User $author,
        string $body,
        array $attachments = [],
    ): TicketComment {
        return DB::transaction(function () use ($ticket, $author, $body, $attachments): TicketComment {
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'user_id' => $author->id,
                'body' => $body,
            ]);

            /*
             * Inside the transaction, so a file that cannot be stored takes the
             * comment with it rather than leaving a line that refers to
             * something nobody can open.
             *
             * The bytes are written outside the database's reach, so a rollback
             * afterwards leaves a file on disk with no row pointing at it. That
             * is wasted space, not a leak — nothing can reach it.
             */
            foreach ($attachments as $file) {
                $this->store($comment, $ticket, $file);
            }

            if ($ticket->first_responded_at === null && $ticket->opened_by !== $author->id) {
                $ticket->forceFill(['first_responded_at' => $comment->created_at])->save();
            }

            TicketUpdated::dispatch($ticket);

            return $comment;
        });
    }

    /**
     * Put one file on the private disk and record where it went.
     *
     * The workspace has the last word on what may be shared, exactly as it does
     * for a message: a workspace that takes no files takes none here either,
     * and one that takes only images is not talked round by a ticket.
     */
    private function store(TicketComment $comment, Ticket $ticket, UploadedFile $file): void
    {
        $workspace = $ticket->channel?->workspace;

        if ($workspace === null || ! $workspace->acceptsAttachment((string) $file->getMimeType())) {
            return;
        }

        // The same disk the message attachments use — "local" is the private
        // one here, rooted at storage/app/private. See config/filesystems.php.
        $path = $file->store('ticket-comments/'.$comment->id, 'local');

        if ($path === false) {
            return;
        }

        $comment->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            // The name it arrived with, kept apart from the random one it is
            // stored under: what a reader downloads should be what a sender
            // sent, and a filename is not a safe thing to build a path from.
            'name' => $file->getClientOriginalName(),
            'mime_type' => (string) $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
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
