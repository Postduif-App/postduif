<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class Webhooks extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.webhooks.label');
    }

    public static function description(): string
    {
        return __('features.webhooks.description');
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Automation;
    }
}
