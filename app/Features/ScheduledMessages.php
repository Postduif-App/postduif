<?php

namespace App\Features;

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
}
