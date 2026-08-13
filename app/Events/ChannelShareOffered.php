<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A workspace offered one of its channels to another workspace.
 *
 * The news belongs to the guest rather than to the host: the host has just done
 * this on purpose and needs no telling, while the guest has an offer sitting
 * there that somebody has to answer. Which of the two workspaces a workflow
 * starts in is decided by the listener, not here — this event only says what
 * happened.
 */
class ChannelShareOffered
{
    use Dispatchable;

    public function __construct(public readonly int $shareId) {}
}
