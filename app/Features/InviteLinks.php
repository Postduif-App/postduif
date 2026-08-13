<?php

namespace App\Features;

use App\Enums\FeatureGroup;

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

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Outside;
    }
}
