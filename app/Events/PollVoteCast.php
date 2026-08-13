<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody ticked an answer, or unticked one.
 *
 * Both, because both change the count and the count is what anybody waiting on
 * a poll is watching. Which of the two it was is $ticked — a workflow that only
 * wants the ticking says so in a condition, and one that wants "de stand is
 * veranderd" needs no condition at all.
 *
 * On a single-choice poll a new vote quietly removes the old one, and only the
 * new one is announced: from the outside that is one person changing their
 * mind, not one leaving and another arriving.
 */
class PollVoteCast
{
    use Dispatchable;

    public function __construct(
        public readonly string $pollId,
        public readonly int $optionId,
        public readonly int $voterId,
        /** Whether the option is ticked afterwards. False when the vote was taken off. */
        public readonly bool $ticked,
    ) {}
}
