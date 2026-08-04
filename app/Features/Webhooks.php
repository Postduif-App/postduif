<?php

namespace App\Features;

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
}
