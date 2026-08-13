<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The signed copy could not be composed, and the job has given up.
 *
 * Not news about the agreement: the contract is signed, complete and unharmed,
 * and this says nothing about that. It is news about us — a PDF step that
 * failed after every retry, leaving a finished contract with no document to
 * download.
 *
 * Fired from the job's failed() hook, so it arrives once, after the last
 * attempt, rather than once per attempt. Worth an event because the person who
 * has to do something about it is not the author being told to wait: it is
 * whoever keeps the workspace running, and until now nothing reached them.
 */
class ContractRenderFailed
{
    use Dispatchable;

    public function __construct(public readonly string $contractId) {}
}
