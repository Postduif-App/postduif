<?php

namespace App\Features;

class InviteLinks extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.invite-links.label');
    }

    public static function description(): string
    {
        return __('features.invite-links.description');
    }
}
