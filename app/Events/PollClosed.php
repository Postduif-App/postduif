<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A poll stopped taking votes.
 *
 * Either way it can happen: somebody pressed stop, or its own moment passed.
 * The two are stored apart — closed_at against closes_at — because the card in
 * the channel reads differently for them and PollController::reopen undoes them
 * separately, but a poll that has finished has finished, and a workflow waiting
 * for the tally has no business caring which of the two it was.
 *
 * The second kind used to be silent, and that was the awkward half: a deadline
 * is compared where the poll is read, so a poll that ran out at midnight was
 * closed from midnight without anything having run to say so. SettlePolls is
 * what runs now — a sweep that stamps settled_at and dispatches this, without
 * touching closed_at and without pretending anybody pressed anything.
 */
class PollClosed
{
    use Dispatchable;

    public function __construct(public readonly string $pollId) {}
}
