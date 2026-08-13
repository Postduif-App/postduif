<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody fetched what was sent to them.
 *
 * Metadata and nothing else, on purpose. What is in a transfer is the sender's
 * and the recipient's business — a workflow gets to know that it was collected,
 * by which recipient row, and how often, and never what was in it.
 *
 * The one thing a sender actually waits for: "heeft de klant het opgehaald" is
 * the question the download counter exists to answer, and until now it could
 * only be answered by going and looking.
 */
class TransferDownloaded
{
    use Dispatchable;

    public function __construct(
        public readonly string $transferId,
        public readonly ?int $recipientId = null,
        public readonly ?int $userId = null,
    ) {}
}
