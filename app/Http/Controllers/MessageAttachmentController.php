<?php

namespace App\Http\Controllers;

use App\Actions\Chat\DeleteMessage;
use App\Events\MessageEdited;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Handing out a file that was shared in a channel.
 *
 * Attachments live on a private disk, so there is no URL that works on its own.
 * This is the only way to them, and it asks the same question the channel asks:
 * may you see this channel at all. Without it a screenshot from a private
 * channel would be one forwarded link away from the whole internet.
 */
class MessageAttachmentController extends Controller
{
    public function show(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Message $message,
        Media $media,
    ): BinaryFileResponse {
        $this->ensureBelongsTogether($workspace, $channel, $message, $media);

        $this->authorize('view', $channel);

        /*
         * The small copy when it exists and was asked for. Only images have
         * one; asking for it on a PDF falls back to the original rather than
         * 404, because "show me the thumbnail" is a request about how to
         * display it, not about which file is meant.
         */
        $wantsPreview = $request->query('c') === 'preview';
        $path = $wantsPreview && $media->hasGeneratedConversion('preview')
            ? $media->getPath('preview')
            : $media->getPath();

        abort_unless(is_file($path), 404);

        $type = $wantsPreview && $media->hasGeneratedConversion('preview')
            ? 'image/webp'
            : ($media->mime_type ?? 'application/octet-stream');

        /*
         * Inline for the things a conversation shows, a download for everything
         * else — unless the reader asked for the file itself, which the button
         * under an image does.
         *
         * The "everything else" half is a security line, not a nicety. The
         * route sits on the application's own origin, so an uploaded .html or
         * .svg served inline would run its script as us: a stored XSS that any
         * member could plant by dragging a file into a channel. Note the
         * asymmetry — asking for a download is always granted, asking to see
         * something in place is not.
         */
        $inline = $this->isSafeToShow($type) && ! $request->boolean('download');

        $response = response()->file($path, [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($media->file_name).'"',

            // And no guessing around the type we just decided on.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Set on the response rather than handed in: a file response fills in
        // Content-Type from the bytes on disk, which is a second opinion we did
        // not ask for.
        $response->headers->set('Content-Type', $type);

        return $response;
    }

    /**
     * Take a shared file back.
     *
     * Judged by the same rule as deleting the message it hangs on: the author,
     * a platform moderator, or — for a bot's output — whoever runs the channel.
     * A file is part of what somebody said, so who may take it back is the same
     * question as who may take the words back.
     */
    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Message $message,
        Media $media,
        DeleteMessage $deleteMessage,
    ): RedirectResponse {
        $this->ensureBelongsTogether($workspace, $channel, $message, $media);

        $this->authorize('delete', $message);

        $media->delete();

        /*
         * A message that was nothing but this file has nothing left to be.
         *
         * Removing the attachment and leaving the row behind would put an empty
         * line in the conversation — which is not a message, and not something
         * anybody meant to leave there. Deleting goes through the action so the
         * mentions, reactions and pins go with it, exactly as they would if the
         * message had been deleted outright.
         */
        if (trim($message->body) === '' && $message->getMedia(Message::ATTACHMENTS)->isEmpty()) {
            $deleteMessage->handle($message);

            return back();
        }

        // Everyone else is looking at a message that still has one file fewer.
        MessageEdited::dispatch($message->refresh());

        return back();
    }

    /**
     * The four of them have to be one chain: this workspace, this channel, this
     * message, this file.
     *
     * The media table is shared by every model that keeps files, so an id from
     * somewhere else would otherwise resolve here — which is why it is checked
     * by hand rather than left to scoped route binding.
     */
    private function ensureBelongsTogether(
        Workspace $workspace,
        Channel $channel,
        Message $message,
        Media $media,
    ): void {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($message->channel_id === $channel->id, 404);
        abort_unless(
            $media->model_type === $message->getMorphClass()
                && (string) $media->model_id === (string) $message->id,
            404,
        );
    }

    /**
     * Whether the browser may render this in place.
     *
     * An allowlist, deliberately: the dangerous types are the ones that carry
     * script — html, svg, xml — and a blocklist of those is a list somebody has
     * to keep complete forever. Images (bar SVG), video, audio and PDF are what
     * a conversation actually shows.
     */
    private function isSafeToShow(string $type): bool
    {
        if ($type === 'image/svg+xml') {
            return false;
        }

        return str_starts_with($type, 'image/')
            || str_starts_with($type, 'video/')
            || str_starts_with($type, 'audio/')
            || $type === 'application/pdf';
    }
}
