<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A shared channel was taken back.
 *
 * The guest's news, like the offer: their people have just lost a room they
 * were working in, and nothing else tells them so. The host did it and knows.
 */
class ChannelShareRevoked
{
    use Dispatchable;

    public function __construct(public readonly int $shareId) {}
}
