<?php

namespace App\Features;

class SharedChannels extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.shared-channels.label');
    }

    public static function description(): string
    {
        return __('features.shared-channels.description');
    }

    /**
     * Off until somebody says otherwise, for the same reason Transfers and
     * AI-toegang are: this is the switch that lets something out of the
     * workspace. Everything else in a channel — who is in it, who may post,
     * what a guest sees — is decided by people who are themselves inside. A
     * share is decided by one workspace about another, and a beheerder who
     * never asked for that should not have to discover it is on.
     *
     * Asked of both workspaces, never of one. The host cannot offer a channel
     * with this switched off, and the invited workspace cannot accept one —
     * see ShareChannelWithWorkspace and RespondToChannelShare. A single-sided
     * check would let a workspace be pulled into an arrangement it had
     * deliberately switched off.
     */
    public static function default(): bool
    {
        return false;
    }
}
