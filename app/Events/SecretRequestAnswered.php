<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody filled in a secret request.
 *
 * How many boxes they answered, and nothing about what went in them. That is
 * not a limitation to work around later: the values are encrypted in the
 * browser and this application cannot read them even if a workflow asked. A
 * trigger that could would be the feature undone.
 *
 * What it is for is the handover: "de klant heeft de inloggegevens ingevuld"
 * is the moment somebody has been waiting for, and a workspace can now put a
 * ticket on it instead of asking twice a week.
 */
class SecretRequestAnswered
{
    use Dispatchable;

    public function __construct(
        public readonly string $secretRequestId,
        public readonly int $answered,
        public readonly ?int $userId = null,
    ) {}
}
