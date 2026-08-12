<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentFile;

class PruneDocuments
{
    /**
     * How long a deleted document stays in the bin.
     *
     * Long, and much longer than a transfer or a secret gets. Those two are
     * cleared to hand storage and risk back; this one exists only because the
     * soft delete would otherwise never end. A document is the thing in a
     * channel that took months and exists nowhere else, and DeleteDocument is
     * deliberately soft for exactly that reason — a fortnight would quietly
     * take that safety net away again.
     */
    public const GRACE_DAYS = 30;

    /**
     * How long a version of a document is kept, and how many.
     *
     * Two limits rather than one, because they answer different worries. The
     * days are for the document somebody writes in every afternoon: nobody
     * wants to scroll back to March, and every row is a full copy of the body.
     * The floor is for the document nobody has touched since spring — clearing
     * its history because it is old would leave the settled documents, the ones
     * that are hardest to reconstruct, with no way back at all.
     */
    public const REVISION_DAYS = 30;

    public const REVISIONS_KEPT = 10;

    /** No document keeps more than this, however busy the afternoon was. */
    public const REVISIONS_MAX = 50;

    /**
     * Finally throw away the documents that were thrown away a month ago, and
     * trim the history of the ones that are still here.
     *
     * @return int How many documents were removed. The trimmed revisions are
     *             not counted: they are housekeeping inside a document that is
     *             still there, and reporting them as removals would read as if
     *             something had been lost.
     */
    public function handle(): int
    {
        $removed = 0;

        Document::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays(self::GRACE_DAYS))
            ->each(function (Document $document) use (&$removed): void {
                $this->removeFiles($document);

                $document->forceDelete();
                $removed++;
            });

        $this->trimRevisions();

        return $removed;
    }

    /**
     * Keep every history within both limits.
     *
     * Per document rather than in one sweeping query, because both rules count
     * from the newest revision of that document — "the tenth most recent" has
     * no meaning across the whole table.
     */
    private function trimRevisions(): void
    {
        Document::query()->each(function (Document $document): void {
            $ids = $document->revisions()->pluck('id');

            // Newest first, so anything past the floor is the older half.
            $beyondFloor = $ids->slice(self::REVISIONS_KEPT);

            if ($beyondFloor->isEmpty()) {
                return;
            }

            $document->revisions()
                ->whereIn('id', $beyondFloor)
                ->where(fn ($query) => $query
                    ->where('created_at', '<', now()->subDays(self::REVISION_DAYS))
                    ->orWhereIn('id', $ids->slice(self::REVISIONS_MAX)))
                ->delete();
        });
    }

    /**
     * The pictures and files inside it, taken off the disk first.
     *
     * This is the part that cannot be left to the database. The foreign key on
     * document_files cascades, so forcing the document away does remove the
     * rows — but a cascade happens inside PostgreSQL, where Eloquent never
     * hears about it, and the deleted() hook that unlinks the bytes never runs.
     * The rows would go and the files would stay behind forever, which is the
     * one outcome this whole command exists to prevent.
     *
     * One at a time and through the model, for that same reason.
     */
    private function removeFiles(Document $document): void
    {
        $document->files()->each(fn (DocumentFile $file) => $file->delete());
    }
}
