<?php

namespace App\Features;

class AiAccess extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'AI-toegang';
    }

    public static function description(): string
    {
        return 'AI-clients mogen met een token meelezen en meepraten in deze workspace.';
    }

    /**
     * The one feature that starts switched off.
     *
     * Everything else here is the product a workspace signed up for. This one
     * hands a copy of the conversation to something outside it, and that is a
     * decision somebody should have to make out loud rather than discover.
     */
    public static function default(): bool
    {
        return false;
    }
}
