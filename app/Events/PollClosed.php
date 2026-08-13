<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody stopped a poll.
 *
 * Somebody, and that is the whole of what this covers today. A poll can be shut
 * in two ways — closed_at, which is a person pressing stop, and closes_at,
 * which is a moment passing — and only the first of them happens *at* a moment
 * anything could hang off. Nothing sweeps the second: the deadline is compared
 * when the poll is read, so a poll that ran out at midnight has been closed
 * since midnight without anything having run.
 *
 * That gap is written up rather than papered over. Stamping closed_at from a
 * sweep would fire this event but would also destroy the distinction the card
 * relies on — "iemand stopte dit" reads differently in a channel from "de tijd
 * was om", and PollController keeps them apart on purpose.
 */
class PollClosed
{
    use Dispatchable;

    public function __construct(public readonly string $pollId) {}
}
