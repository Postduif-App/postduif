<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The guest workspace said yes or no to a shared channel.
 *
 * Both in one event, because they are the same moment answered two ways and the
 * host is waiting for either. What the host does about it differs, which is a
 * condition rather than a second trigger.
 */
class ChannelShareAnswered
{
    use Dispatchable;

    public function __construct(
        public readonly int $shareId,
        public readonly bool $accepted,
    ) {}
}
