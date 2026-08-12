<?php

namespace App\Features;

class Contracts extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.contracts.label');
    }

    public static function description(): string
    {
        return __('features.contracts.description');
    }

    /*
     * Off by default, with Transfers, Forms and SecretRequests.
     *
     * It does both of the things that put a feature in that group at once: it
     * hands a document to somebody outside the workspace, and it collects what
     * they type and draw into it. A workspace should have decided out loud that
     * it wants to ask people for their signature, rather than find out it
     * already could.
     */
    public static function default(): bool
    {
        return false;
    }
}
