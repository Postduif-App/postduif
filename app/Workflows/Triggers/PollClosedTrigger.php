<?php

namespace App\Workflows\Triggers;

/**
 * A poll stopped taking votes.
 *
 * Both ways it can end: somebody pressing stop, and the poll's own deadline
 * going by. The second used to be missing here — nothing ran at that moment to
 * notice it — which quietly left out half of all polls; SettlePolls is the
 * minute-by-minute sweep that closed that gap.
 *
 * What it is for is the tally. The result of a poll is only worth reporting
 * once nobody can still change it, which is exactly this moment.
 */
class PollClosedTrigger extends PollTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.poll-closed.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.poll-closed.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return static::pollProvides();
    }
}
