<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody followed their link and looked at the contract for the first time.
 *
 * Once per signer and never again, because that is what the column behind it
 * records: opened_at is stamped the first time and left alone after. A second
 * event on every reload would say nothing and arrive constantly.
 *
 * What it is for is the thing nobody can otherwise tell apart: a contract
 * nobody has answered may be one that never arrived, or one that arrived and is
 * being thought about. "Drie dagen geleden verstuurd en nog niet geopend" is a
 * reason to telephone; "geopend en niet getekend" is a reason to wait.
 */
class ContractOpened
{
    use Dispatchable;

    public function __construct(
        public readonly string $contractId,
        public readonly string $signerId,
    ) {}
}
