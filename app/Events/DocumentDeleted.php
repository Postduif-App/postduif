<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A document was taken out of the channel.
 *
 * Soft-deleted rather than gone — see DeleteDocument, which explains why a
 * document is the one thing in a channel that never really goes. So a listener
 * can still read the row, and has to ask for it with withTrashed() to find it.
 *
 * Not announced in the channel by the application itself: a notice that
 * something has been removed only tells people about a document they can no
 * longer read. A workspace that wants that anyway can now build it, which is
 * the reason this event exists at all.
 */
class DocumentDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly int $documentId,
        public readonly int $actorId,
    ) {}
}
