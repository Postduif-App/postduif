<?php

namespace App\Actions\Documents;

use App\Events\DocumentDeleted;
use App\Events\DocumentUpdated;
use App\Models\Document;
use App\Models\User;

class DeleteDocument
{
    /**
     * Take a document out of the channel.
     *
     * Soft, and that is the point rather than a default that came along with
     * the trait. A document is the one thing in a channel that is not a stream of
     * separate remarks — it is a single object that people have been adding to
     * for months, and there is no version of it left anywhere else. A hard
     * delete would be one wrong click against all of that.
     *
     * The number is not released. Somebody who wrote down "zie document #4" has
     * to keep meaning the same document, including when the answer is that it
     * is gone.
     *
     * Not announced in the channel. Creating one is news the channel can act
     * on; a notice that something has been removed only tells people about a
     * document they can no longer read.
     */
    public function handle(Document $document, User $actor): void
    {
        /*
         * Recorded before the delete rather than after: soft-deleting still
         * writes the row, so this rides along in the same UPDATE, and it is
         * the only trace left of who did it.
         */
        $document->forceFill(['updated_by' => $actor->id])->save();

        $document->delete();

        // The list has to lose it for everybody, not only for whoever removed
        // it — they are already being sent back to it.
        broadcast(new DocumentUpdated($document))->toOthers();

        // Soft-deleted, so a listener can still read the row — with
        // withTrashed(), which is the only way it will find it.
        DocumentDeleted::dispatch($document->id, $actor->id);
    }
}
