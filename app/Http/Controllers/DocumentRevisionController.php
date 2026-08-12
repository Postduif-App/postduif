<?php

namespace App\Http\Controllers;

use App\Actions\Documents\RestoreDocumentRevision;
use App\Models\Channel;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * The history of a document: what it said before, and putting one of those
 * versions back.
 *
 * The list is fetched rather than carried in the page props, and that is worth
 * a word. A history is a handful of full copies of the document — the current
 * one is already on the page, and shipping fifty more with every visit to a
 * channel would make the chat pay for a panel almost nobody opens.
 */
class DocumentRevisionController extends Controller
{
    public function index(
        Workspace $workspace,
        Channel $channel,
        Document $document,
    ): JsonResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);

        $this->authorize('viewHistory', $document);

        $document->load('revisions.author');

        return response()->json([
            'revisions' => $document->revisions
                ->map(fn (DocumentRevision $revision): array => [
                    'id' => $revision->id,
                    /*
                     * A name and a moment and a line of the text. Not the body:
                     * fifty documents' worth of JSON to draw a list of dates is
                     * the thing this endpoint exists to avoid, and the body is
                     * only ever needed for the one that gets restored — which
                     * the server does itself.
                     */
                    'author' => $revision->author?->name,
                    'createdAt' => $revision->created_at?->toIso8601String(),
                    'excerpt' => $revision->excerpt(),
                ])
                ->all(),
        ]);
    }

    /**
     * One version in full, to read before deciding.
     *
     * Apart from the list rather than folded into it, and that is the whole
     * reason the list carries only a line of text: nobody should download fifty
     * copies of a document to look at one of them. This is the one they asked
     * to see.
     */
    public function show(
        Workspace $workspace,
        Channel $channel,
        Document $document,
        DocumentRevision $revision,
    ): JsonResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($revision->document_id === $document->id, 404);

        $this->authorize('viewHistory', $document);

        return response()->json([
            'id' => $revision->id,
            'body' => $revision->body === [] ? new stdClass : $revision->body,
        ]);
    }

    /**
     * Put one back.
     *
     * A redirect rather than JSON, unlike the list: the document on screen has
     * to become the restored one, and that is a page the server draws. back()
     * for the same reason the ordinary save uses it — the caret and the scroll
     * belong to whoever is looking at it.
     */
    public function restore(
        Request $request,
        Workspace $workspace,
        Channel $channel,
        Document $document,
        DocumentRevision $revision,
        RestoreDocumentRevision $restoreDocumentRevision,
    ): RedirectResponse {
        abort_unless($channel->workspace_id === $workspace->id, 404);
        abort_unless($revision->document_id === $document->id, 404);

        $this->authorize('viewHistory', $document);

        $restoreDocumentRevision->handle($document, $revision, $request->user());

        return back();
    }
}
