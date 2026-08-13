<?php

namespace App\Workflows\Triggers;

/**
 * Somebody stopped a poll.
 *
 * Somebody, and only somebody. A poll that shuts because its own deadline
 * passed announces nothing — nothing is running at that moment to notice, and
 * the deadline is compared when the poll is read rather than swept. A workflow
 * written here will not fire for those, and that gap is a known one rather than
 * a bug: see the event, and pcom-ybal.21.
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
