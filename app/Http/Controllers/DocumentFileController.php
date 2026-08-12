<?php

namespace App\Http\Controllers;

use App\Actions\Documents\StoreDocumentFile;
use App\Http\Requests\StoreDocumentFileRequest;
use App\Models\Channel;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files inside a document: putting one there, handing it back out, and
 * taking it away.
 *
 * The same shape as MessageAttachmentController and its ticket counterpart, and
 * deliberately so: these files sit on the private disk, this is the only way to
 * them, and it asks the question the document asks — may you read this at all.
 * A document is exactly as private as its channel, so nothing new had to be
 * decided about who gets in.
 */
class DocumentFileController extends Controller
{
    /**
     * Types a browser may be told to render in place.
     *
     * A security line rather than a nicety. The route sits on the application's
     * own origin, so an uploaded .html or .svg served inline would run its
     * script as us — a stored XSS anybody who may write in a document could
     * plant. Note the asymmetry: asking for a download is always granted,
     * asking to see something in place is not.
     *
     * @var array<int, string>
     */
    private const SHOWABLE = ['image/', 'video/', 'audio/', 'application/pdf'];

    /**
     * Take a file the writer just dropped into the editor.
     *
     * JSON rather than a redirect, because this is not a form being submitted:
     * the editor is mid-sentence and needs the id and the address back so it
     * can put a block where the cursor is. A redirect would rebuild the page
     * and take the caret with it.
     */
    public function store(
        StoreDocumentFileRequest $request,
        Workspace $workspace,
        Channel $channel,
        Document $document,
        StoreDocumentFile $storeDocumentFile,
    ): JsonResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $file = $storeDocumentFile->handle(
            document: $document,
            uploader: $request->user(),
            file: $request->file('file'),
        );

        return response()->json([
            /*
             * The id is the half that matters for the long run: the editor
             * writes it into the body, and ReconcileDocumentFiles reads it back
             * to decide what is still in use. The URL is for right now.
             */
            'id' => $file->id,
            'url' => $file->url(),
            'name' => $file->name,
            'mimeType' => $file->mime_type,
            'size' => $file->size,
            'width' => $file->width,
            'height' => $file->height,
        ], Response::HTTP_CREATED);
    }

    public function show(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Document $document,
        DocumentFile $file,
    ): BinaryFileResponse {
        $this->ensureBelongsTogether($workspace, $channel, $document, $file);

        /*
         * view rather than update: a guest may read a channel's documents
         * without being allowed to write in them, and a page whose pictures are
         * all broken boxes is not a page they can read.
         */
        $this->authorize('view', $document);

        $disk = Storage::disk($file->disk);

        abort_unless($disk->exists($file->path), 404);

        $type = $file->mime_type;
        $inline = $this->isSafeToShow($type) && ! $request->boolean('download');

        $response = response()->file($disk->path($file->path), [
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                .'; filename="'.addslashes($file->name).'"',

            // No guessing around the type we just decided on.
            'X-Content-Type-Options' => 'nosniff',
        ]);

        // Set on the response rather than handed in: a file response fills in
        // Content-Type from the bytes on disk, which is a second opinion we did
        // not ask for.
        $response->headers->set('Content-Type', $type);

        return $response;
    }

    /**
     * Take a file out of a document straight away.
     *
     * Reconciliation would get to it on the next save, an hour later — which is
     * right for a file somebody stopped mentioning, and wrong for one somebody
     * deliberately removed. Uploading the wrong picture into a shared page is
     * exactly the moment to be able to say "no, now".
     */
    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Document $document,
        DocumentFile $file,
    ): JsonResponse {
        $this->ensureBelongsTogether($workspace, $channel, $document, $file);

        $this->authorize('update', $document);

        $file->delete();

        return response()->json(status: Response::HTTP_NO_CONTENT);
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
     * The four of them have to be one chain: this workspace, this channel, this
     * document, this file.
     *
     * Checked rather than assumed, because an id from elsewhere would otherwise
     * resolve perfectly well and hand out a file from a channel the reader
     * cannot open.
     */
    private function ensureBelongsTogether(
        Workspace $workspace,
        Channel $channel,
        Document $document,
        DocumentFile $file,
    ): void {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($document->channel_id === $channel->id, 404);
        abort_unless($file->document_id === $document->id, 404);
    }
}
