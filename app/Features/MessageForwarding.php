<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class MessageForwarding extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.message-forwarding.label');
    }

    public static function description(): string
    {
        return __('features.message-forwarding.description');
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Conversation;
    }
}
