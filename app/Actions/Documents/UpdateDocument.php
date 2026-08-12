<?php

namespace App\Actions\Documents;

use App\Events\DocumentUpdated;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class UpdateDocument
{
    public function __construct(
        private readonly AnnounceDocument $announceDocument,
        private readonly ReconcileDocumentFiles $reconcileDocumentFiles,
        private readonly RecordDocumentRevision $recordDocumentRevision,
    ) {}

    /**
     * Save a document, unless somebody else already did.
     *
     * The version the browser sends back is the one it was handed when it
     * opened the document. If the stored version has moved on, another person
     * saved in between and writing now would erase their work without either
     * of them noticing. Autosave makes that ordinary rather than rare: it fires
     * every few seconds of quiet, so two people in one document is a Tuesday.
     *
     * The check and the write are one transaction with the row locked. Reading
     * the version, deciding, and then writing would leave a gap in which the
     * very thing being guarded against happens.
     *
     * @param  bool  $replacesWholesale  Whether this save puts something else
     *                                   in place of the document rather than
     *                                   continuing to write in it. Then the
     *                                   previous text is kept whatever the
     *                                   coalescing window says — see
     *                                   RecordDocumentRevision.
     * @param  array<string, mixed>|null  $body  Null leaves the document alone,
     *                                           which is how a rename saves
     *                                           without shipping the whole
     *                                           document back up.
     *
     * @throws ValidationException When the document moved on underneath.
     * @throws InvalidArgumentException When text arrives without its document.
     */
    public function handle(
        Document $document,
        User $editor,
        int $expectedVersion,
        ?string $title = null,
        ?array $body = null,
        ?string $bodyText = null,
        bool $replacesWholesale = false,
    ): Document {
        /*
         * The flattened text is not a field of its own — it is the document
         * seen from a different angle, and storing one without the other leaves
         * the search index describing a version that no longer exists.
         *
         * Loudly rather than quietly ignored: a caller that sends only the text
         * believes it saved something, and the failure would otherwise surface
         * weeks later as a document that cannot be found by its own words.
         */
        if ($bodyText !== null && $body === null) {
            throw new InvalidArgumentException(
                'A document body text without a body: the two are one document and travel together.',
            );
        }

        $renamedTo = null;

        $saved = DB::transaction(function () use (
            $document, $editor, $expectedVersion, $title, $body, $bodyText, $replacesWholesale, &$renamedTo
        ): Document {
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();

            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => __('documents.conflict.message', [
                        // Plain -> and not ?->, which reads like a slip and is not:
                        // ?? already swallows a read on null, so the nullsafe would
                        // only be a second guard against the same nothing.
                        'name' => $locked->editor->name ?? __('documents.conflict.somebody'),
                    ]),
                ]);
            }

            if ($title !== null && $title !== $locked->title) {
                $renamedTo = $title;
                $locked->title = $title;
            }

            if ($body !== null) {
                /*
                 * Keep what is there before it is replaced, and do it inside
                 * the same transaction as the write.
                 *
                 * Not for tidiness: outside it there is a gap in which the new
                 * body is stored and the old one has not been kept, and a
                 * crash in that gap loses exactly the thing this exists to
                 * save. Either both happen or neither does.
                 *
                 * The lock is already held, so nothing can slip in between.
                 */
                $this->recordDocumentRevision->handle($locked, $editor, $replacesWholesale);

                $locked->body = $body;
                /*
                 * The flattened text comes from the client, which already has
                 * it: the editor can produce it for free and the server would
                 * have to walk a JSON tree whose shape belongs to whichever
                 * plugins happen to be installed. Falling back to the empty
                 * string rather than keeping the old text — stale search text
                 * for a document that changed is worse than none.
                 */
                $locked->body_text = $bodyText ?? '';
            }

            $locked->updated_by = $editor->id;
            $locked->version = $expectedVersion + 1;
            $locked->save();

            return $locked;
        });

        /*
         * Only when the body actually changed: a rename says nothing about
         * which files the document mentions, and running this on one would put
         * a delete behind every edit of a title.
         *
         * Outside the transaction as well, and for a plainer reason than the
         * announcement below — this removes files from disk, and a rollback
         * cannot put those back.
         */
        if ($body !== null) {
            $this->reconcileDocumentFiles->handle($saved);
        }

        // Outside the transaction, for the reason set out in CreateDocument.
        if ($renamedTo !== null) {
            $this->announceDocument->renamed($saved, $editor);
        }

        /*
         * toOthers() is load-bearing here rather than a nicety. This fires on
         * every autosave, and a writer who received their own saves back would
         * spend the whole session reloading themselves.
         */
        broadcast(new DocumentUpdated($saved))->toOthers();

        return $saved;
    }
}
