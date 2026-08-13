<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contract went out to the people who have to sign it.
 *
 * The beginning of the story the other contract events tell the rest of.
 * Fired once per contract, after the invitations have been handed to the
 * mailer — a contract that could not be sent should not announce that it was.
 *
 * An id rather than the model, for the reasons set out on ContractSigned: every
 * listener is either a queue away or about to read the row anyway, and an id
 * cannot go stale in between.
 *
 * Never fired for a template. A template is copied and the copy is sent, so the
 * event belongs to the copy — see SendContract, which refuses to send a
 * template at all.
 */
class ContractSent
{
    use Dispatchable;

    public function __construct(public readonly string $contractId) {}
}
