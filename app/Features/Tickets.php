<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class Tickets extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.tickets.label');
    }

    public static function description(): string
    {
        return __('features.tickets.description');
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Work;
    }
}
