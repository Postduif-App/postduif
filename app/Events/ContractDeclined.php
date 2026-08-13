<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody read a contract and said no.
 *
 * An outcome rather than a failure, and worth its own event for the same reason
 * the button exists: without it, a system waiting to hear about a contract it
 * sent would wait forever on somebody who has already decided. What the refusal
 * closes is the whole contract, not one person's part of it — so this is
 * followed by ContractCompleted as surely as the last signature would have been.
 *
 * Ids rather than models, for the reasons set out on ContractSigned. The reason
 * somebody gave is not carried here either: it is on the signer's row, and a
 * listener that wants it is already reading that row.
 */
class ContractDeclined
{
    use Dispatchable;

    public function __construct(
        public readonly string $contractId,
        public readonly string $signerId,
    ) {}
}
