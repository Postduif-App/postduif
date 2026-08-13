<?php

namespace App\Http\Controllers;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\UpdateDocument;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Channel;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

/**
 * Documents are read through the chat page rather than through a page of their
 * own, exactly as the ticket board is: a document is a second view of a channel
 * and it needs the same sidebar, the same unread counts and the same live
 * connection. What lives here is everything that changes one.
 *
 * Which document is open travels in the query string, so a document is linkable
 * and survives a refresh — which matters more here than for a ticket, because
 * "zie het document" in a message has to be a link somebody can follow.
 */
class DocumentController extends Controller
{
    public function store(
        StoreDocumentRequest $request,
        Workspace $workspace,
        Channel $channel,
        CreateDocument $createDocument,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        $document = $createDocument->handle(
            channel: $channel,
            author: $request->user(),
            title: $request->string('title')->trim()->value(),
        );

        // Straight into the new document rather than back to the list. Nobody
        // creates a document in order to look at its name.
        return redirect()->route('chat.show', [
            $workspace,
            $channel,
            'view' => 'documents',
            'document' => $document->number,
        ]);
    }

    /**
     * Save a document: its title, its document, or both.
     *
     * back() rather than a redirect to the document, and that is deliberate. This
     * is what autosave calls every few seconds of quiet while somebody is
     * typing, and a redirect would rebuild the page — and the caret with it —
     * under their hands.
     */
    public function update(
        UpdateDocumentRequest $request,
        Workspace $workspace,
        Channel $channel,
        Document $document,
        UpdateDocument $updateDocument,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        $updateDocument->handle(
            document: $document,
            editor: $request->user(),
            expectedVersion: $request->integer('version'),
            title: $request->has('title')
                ? $request->string('title')->trim()->value()
                : null,
            /*
             * array() rather than input(), so an absent body stays absent
             * instead of arriving as an empty array — which the action would
             * read as "empty this document".
             */
            body: $request->has('body') ? $request->array('body') : null,
            bodyText: $request->has('body') ? $request->string('body_text')->value() : null,
        );

        return back();
    }

    public function destroy(
        Workspace $workspace,
        Channel $channel,
        Document $document,
        DeleteDocument $deleteDocument,
    ): RedirectResponse {
        $this->channelIsReachable($workspace, $channel);

        $this->authorize('delete', $document);

        $deleteDocument->handle($document, request()->user());

        // Back to the list, which is the only thing left to look at.
        return redirect()->route('chat.show', [
            $workspace,
            $channel,
            'view' => 'documents',
        ]);
    }
}
