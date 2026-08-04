<?php

namespace App\Features;

class SavedMessages extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.saved-messages.label');
    }

    public static function description(): string
    {
        return __('features.saved-messages.description');
    }
}
