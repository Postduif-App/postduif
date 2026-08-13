<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody put their name to a contract.
 *
 * Fired once per signer, the last one included — a contract with three parties
 * is three of these. What makes the last different is only that ContractCompleted
 * follows it, once the signed PDF has been composed.
 *
 * Ids rather than models, which is the one place this parts company with
 * FormSubmitted. That event hands over the submission itself because its
 * listeners want the relations the action already had in hand. Here every
 * listener is a queue away: the payload is serialised into Redis, and a model
 * that has to survive that round trip either travels as a fat snapshot of a row
 * or comes back refreshed anyway. Two ids are honest about what actually
 * crosses, and they cannot go stale on the way — a contract that was completed
 * a second after this fired should be read as completed by whoever picks it up.
 *
 * Never fired for a template. Its author signing it is not news about an
 * agreement; it is them putting half a document in place for the contracts that
 * will be made from it — see SignContract, which is where that guard lives.
 */
class ContractSigned
{
    use Dispatchable;

    public function __construct(
        public readonly string $contractId,
        public readonly string $signerId,
    ) {}
}
