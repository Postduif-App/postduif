<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contract is finished and the signed copy is on disk.
 *
 * Fired from RenderSignedContractJob rather than from the moment the status
 * turned Completed, and the difference is the whole point of the event. The
 * contract is complete a good few seconds before the PDF exists — the status is
 * set inside the signing transaction, the document is composed on a queue —
 * and anything that hears "klaar" and immediately goes to fetch the document
 * would find nothing there. Fired here, the link in the payload works the
 * moment it arrives.
 *
 * No signer, unlike its two siblings. Nobody in particular completes a
 * contract: the last answer does, and whoever gave it already has their own
 * ContractSigned or ContractDeclined. Passing them along again would invite a
 * receiving system to read this as "signed by", which is wrong for every
 * contract that ended in a refusal.
 */
class ContractCompleted
{
    use Dispatchable;

    public function __construct(public readonly string $contractId) {}
}
