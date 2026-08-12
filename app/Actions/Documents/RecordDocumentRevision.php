<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;

/**
 * Keep what the document said before this save replaces it.
 *
 * Called from inside UpdateDocument's transaction, with the locked row and
 * before the new body is written — which is the only moment the old one still
 * exists.
 */
class RecordDocumentRevision
{
    /**
     * How long one revision stands for.
     *
     * Autosave fires after eight hundred milliseconds of quiet, so a row per
     * save would be several hundred rows for an afternoon's writing, and a
     * history nobody can read is not a history. Instead a revision covers a
     * stretch of work: the first save after a quiet spell records where things
     * stood, and everything typed in the next ten minutes is one step.
     *
     * Ten rather than sixty, because the thing people want back is usually what
     * they had a moment ago — the accidental select-and-type, noticed straight
     * away.
     */
    public const COALESCE_MINUTES = 10;

    /**
     * @param  User  $editor  Whoever is saving now. Used to decide whether this
     *                        is a new hand on the document, not to credit the
     *                        revision — the text being kept was written by
     *                        somebody else, and that is who it is filed under.
     * @param  bool  $always  Skip the coalescing and keep this one whatever the
     *                        clock says. For a save that replaces the document
     *                        wholesale rather than continuing to type in it —
     *                        see RestoreDocumentRevision, where letting the
     *                        window swallow the revision would mean a restore
     *                        that quietly threw away the text it replaced.
     */
    public function handle(Document $document, User $editor, bool $always = false): void
    {
        /*
         * Nothing to keep. A document that has never had anything in it has no
         * past worth a row, and this is the ordinary state of one that was made
         * a minute ago.
         */
        if ($document->body === []) {
            return;
        }

        if (! $always && ! $this->worthKeeping($document, $editor)) {
            return;
        }

        DocumentRevision::create([
            'document_id' => $document->id,
            /*
             * The previous editor, falling back to whoever started the
             * document — which is who wrote the body being kept when nobody has
             * saved since.
             */
            'created_by' => $document->updated_by ?? $document->created_by,
            'body' => $document->body,
            'body_text' => $document->body_text,
        ]);
    }

    /**
     * Whether this save begins a new step in the history.
     *
     * Three cases, and the third is the one that is easy to leave out: when
     * somebody else takes over, their first save has to close off the previous
     * person's work. Without it, an hour of Alice's writing and then Bob's
     * rewrite would fold into one revision, and "put back what Alice had" —
     * the thing anybody would ask for — would be impossible.
     */
    private function worthKeeping(Document $document, User $editor): bool
    {
        $latest = $document->revisions()->first();

        if ($latest === null) {
            return true;
        }

        if ($latest->created_at?->lt(now()->subMinutes(self::COALESCE_MINUTES)) === true) {
            return true;
        }

        return ($document->updated_by ?? $document->created_by) !== $editor->id;
    }
}
