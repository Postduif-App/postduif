<?php

namespace App\Workflows\Triggers;

/**
 * Another workspace offered us one of their channels.
 *
 * Runs in the *guest* workspace: the offer is sitting there waiting for
 * somebody to answer, and until now nothing pointed at it except a badge on a
 * screen people have to open.
 */
class ChannelShareOfferedTrigger extends ChannelShareTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.channel-share-offered.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.channel-share-offered.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return static::shareProvides();
    }
}
