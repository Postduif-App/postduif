<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody started a document in a channel.
 *
 * The counterpart of DocumentUpdated rather than a rival to it. That one is a
 * broadcast fired on every autosave — every few seconds of quiet while somebody
 * writes — and a workflow hung off it would be a workflow that runs on
 * keystrokes. This fires once, when the document comes into existence, which is
 * the only moment about a document that anything outside it can act on.
 *
 * There is deliberately no DocumentChanged to go with it. What changed inside a
 * document is the document's own business; that it exists, and what it is
 * called, is the channel's — the same line AnnounceDocument draws.
 */
class DocumentCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $documentId,
        public readonly int $authorId,
    ) {}
}
