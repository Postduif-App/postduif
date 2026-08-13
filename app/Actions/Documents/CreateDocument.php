<?php

namespace App\Actions\Documents;

use App\Events\DocumentCreated;
use App\Events\DocumentUpdated;
use App\Models\Channel;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateDocument
{
    public function __construct(
        private readonly AnnounceDocument $announceDocument,
    ) {}

    /**
     * Start a document in a channel.
     *
     * The number is claimed inside the same transaction that writes the document:
     * one that never gets stored must not take a number with it, or the list
     * grows gaps that look like documents somebody deleted.
     *
     * It opens empty rather than with a template. Whatever a template put there
     * is the first thing every writer has to delete, and a document that begins
     * by asking to be tidied up is one people start somewhere else instead.
     */
    public function handle(Channel $channel, User $author, string $title): Document
    {
        $document = DB::transaction(function () use ($channel, $author, $title): Document {
            return Document::create([
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'number' => $channel->workspace->claimDocumentNumber(),
                'title' => $title,
                'body' => Document::emptyBody(),
                'body_text' => '',
                'created_by' => $author->id,
            ]);
        });

        /*
         * Announced outside the transaction, unlike the ticket equivalent.
         * Announcing writes a message, which fans out over the websocket and
         * into unread counts — work that has no business holding a row lock on
         * the workspace counter while it happens. If the announcement fails the
         * document still exists, which is the right way round: a document nobody
         * was told about can be pointed at later, an announcement of a document
         * that was rolled back cannot be taken back.
         */
        $this->announceDocument->created($document, $author);

        // toOthers(): whoever just made it is already being redirected into it.
        broadcast(new DocumentUpdated($document))->toOthers();

        /*
         * And the one a workflow listens for, which is a different animal from
         * the broadcast above: that one says "ga maar opnieuw kijken" to a
         * screen, this one says what happened to whoever is not looking.
         */
        DocumentCreated::dispatch($document->id, $author->id);

        return $document;
    }
}
