<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentAttachment;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Handing out a file that was attached to a ticket comment.
 *
 * The same shape as MessageAttachmentController, and deliberately so: these
 * files sit on the private disk, this is the only way to them, and it asks the
 * question the channel asks — may you see this channel at all. A ticket belongs
 * to a channel, so nothing new had to be decided about who may read it.
 */
class TicketCommentAttachmentController extends Controller
{
    /**
     * Types a browser may be told to render in place.
     *
     * A security line rather than a nicety. The route sits on the application's
     * own origin, so an uploaded .html or .svg served inline would run its
     * script as us — a stored XSS anybody who may comment could plant. Note the
     * asymmetry: asking for a download is always granted, asking to see
     * something in place is not.
     *
     * @var array<int, string>
     */
    private const SHOWABLE = ['image/', 'video/', 'audio/', 'application/pdf'];

    public function show(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Ticket $ticket,
        TicketComment $comment,
        TicketCommentAttachment $attachment,
    ): BinaryFileResponse {
        $this->ensureBelongsTogether($workspace, $channel, $ticket, $comment, $attachment);

        $this->authorize('view', $channel);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        $type = $attachment->mime_type;
        $inline = $this->isSafeToShow($type) && ! $request->boolean('download');

        $response = response()->file($disk->path($attachment->path), [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($attachment->name).'"',

            // No guessing around the type we just decided on.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Set on the response rather than handed in: a file response fills in
        // Content-Type from the bytes on disk, which is a second opinion we did
        // not ask for.
        $response->headers->set('Content-Type', $type);

        return $response;
    }

    private function isSafeToShow(string $mimeType): bool
    {
        foreach (self::SHOWABLE as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The five of them have to be one chain: this workspace, this channel, this
     * ticket, this comment, this file.
     *
     * Checked rather than assumed, because an id from elsewhere would otherwise
     * resolve perfectly well and hand out a file from a channel the reader
     * cannot open.
     */
    private function ensureBelongsTogether(
        Workspace $workspace,
        Channel $channel,
        Ticket $ticket,
        TicketComment $comment,
        TicketCommentAttachment $attachment,
    ): void {
        abort_unless(
            $channel->workspace_id === $workspace->id
                && $ticket->channel_id === $channel->id
                && $comment->ticket_id === $ticket->id
                && $attachment->ticket_comment_id === $comment->id,
            404,
        );
    }
}
