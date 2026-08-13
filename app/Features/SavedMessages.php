<?php

namespace App\Features;

use App\Enums\FeatureGroup;

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

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Conversation;
    }
}
