<?php

namespace App\Features;

class SecretRequests extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.secret-requests.label');
    }

    public static function description(): string
    {
        return __('features.secret-requests.description');
    }

    /**
     * The third that starts switched off, and for a reason of its own.
     *
     * AiAccess and Transfers are off because they let something outside look
     * in. This one is off because of what it collects: the moment a workspace
     * has this, it is holding other people's passwords. That is a thing to
     * decide out loud, not to discover.
     */
    public static function default(): bool
    {
        return false;
    }
}
