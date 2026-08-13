<?php

namespace App\Workflows\Triggers;

/**
 * A channel that was shared with us has been taken back.
 *
 * Runs in the *guest* workspace, for the reason the offer does: their people
 * have just lost a room they were working in, and the host — who did it — needs
 * no telling. By the time this runs the guests are already out of the channel,
 * so a step cannot post there; what it can do is say so somewhere they can
 * still read.
 */
class ChannelShareRevokedTrigger extends ChannelShareTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.channel-share-revoked.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.channel-share-revoked.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return static::shareProvides();
    }
}
