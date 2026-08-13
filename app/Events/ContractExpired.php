<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contract's deadline passed with somebody still to answer.
 *
 * Fired by the nightly prune, one per contract, which is why that method no
 * longer closes them in a single mass update: an UPDATE over a set fires
 * nothing, and a trigger hung off an event that is never dispatched is a
 * workflow that quietly never runs. The ids are read first and the event
 * follows the row.
 *
 * That also means the moment is the prune's rather than the deadline's. A
 * contract whose deadline passed at two in the morning is announced when the
 * command runs — anything asking "may this still be signed" compares the date
 * itself and has considered it closed since two.
 */
class ContractExpired
{
    use Dispatchable;

    public function __construct(public readonly string $contractId) {}
}
