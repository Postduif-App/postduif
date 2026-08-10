<?php

namespace App\Features;

class Documents extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.documents.label');
    }

    public static function description(): string
    {
        return __('features.documents.description');
    }
}
