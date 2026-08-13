<?php

namespace App\Workflows\Triggers;

/**
 * A question was put to a channel.
 *
 * Fires once the poll is complete and its message is in the conversation, so a
 * workflow acting on it describes a whole poll rather than half of one.
 */
class PollCreatedTrigger extends PollTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.poll-created.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.poll-created.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return static::pollProvides();
    }
}
