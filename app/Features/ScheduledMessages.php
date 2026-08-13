<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class ScheduledMessages extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.scheduled-messages.label');
    }

    public static function description(): string
    {
        return __('features.scheduled-messages.description');
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Conversation;
    }
}
