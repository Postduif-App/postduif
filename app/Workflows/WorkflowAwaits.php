<?php

namespace App\Workflows;

use App\Enums\WorkflowAwaitableEvent;
use Carbon\CarbonInterface;

/**
 * A step saying "not now — come back when this happens, or when this moment
 * arrives, whichever is first".
 *
 * Deliberately a WorkflowPaused, so the runner's single catch keeps working and
 * no third path through perform() appears. The difference is entirely in what
 * gets written down: a plain pause stores a moment, and this stores a moment
 * *and* what would cut it short.
 *
 * The deadline is not optional and cannot be. A run waiting for something that
 * never happens is a row that sits in the table until somebody notices, and
 * nobody notices — so every await is also a delay, and the run comes back
 * either way. Which of the two got there first is what the step leaves behind
 * for the branch underneath it.
 */
class WorkflowAwaits extends WorkflowPaused
{
    public function __construct(
        public readonly WorkflowAwaitableEvent $event,
        /**
         * The record this run is waiting on, as a string.
         *
         * A string because the ids it holds are of two kinds — a ticket is an
         * integer, a contract is a ULID — and the only thing ever done with it
         * is comparing it to the id in a happening. Comparing as text is
         * exactly right for that and needs no idea of which kind it is.
         */
        public readonly string $record,
        CarbonInterface $until,
    ) {
        parent::__construct($until);
    }
}
