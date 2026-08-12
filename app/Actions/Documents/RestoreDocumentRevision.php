<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;

/**
 * Put an old version back.
 *
 * The whole of it is one idea: restoring is an ordinary save. It goes through
 * UpdateDocument like anything else, which means it takes the version check
 * with it and — the part that matters — records what was there before it, the
 * same as any other edit.
 *
 * So restoring can never lose anything. Put back yesterday's version by
 * mistake and today's is one step back in the same list. A restore that wiped
 * the newer text would be the same accident this feature exists to undo, only
 * committed by the mechanism meant to fix it.
 */
class RestoreDocumentRevision
{
    public function __construct(private readonly UpdateDocument $updateDocument) {}

    public function handle(
        Document $document,
        DocumentRevision $revision,
        User $editor,
    ): Document {
        return $this->updateDocument->handle(
            document: $document,
            editor: $editor,
            /*
             * The version as it stands rather than one the caller sent.
             *
             * A restore is chosen from a list that was drawn some time ago, and
             * insisting on the version from back then would refuse the restore
             * because somebody typed a word in the meantime — which is when it
             * is needed most. The point of the check is to stop two people
             * overwriting each other unaware; here the person is deliberately
             * replacing what is there, and the thing they are replacing is kept.
             */
            expectedVersion: $document->version,
            body: $revision->body,
            bodyText: $revision->body_text,
            /*
             * Keep what is being replaced, even if a revision was written a
             * minute ago. The coalescing window exists so that autosave does
             * not make a row per keystroke; a restore is the opposite of that —
             * it throws away everything since the chosen version in one go, and
             * that is the moment the promise "restoring loses nothing" is
             * either kept or broken.
             */
            replacesWholesale: true,
        );
    }
}
