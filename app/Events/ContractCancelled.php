<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Whoever asked for the signatures stopped the contract.
 *
 * Fired after the conditional update has actually claimed the contract, never
 * before it. The race CancelContract guards against is a cancel arriving at the
 * same moment as the last signature, and only one of the two may win — so this
 * says "it was stopped", not "somebody pressed stop".
 *
 * No signer and nobody who did it. The action is handed a contract and nothing
 * else, and inventing an actor here would mean inventing one in the two places
 * that call it as well.
 */
class ContractCancelled
{
    use Dispatchable;

    public function __construct(public readonly string $contractId) {}
}
