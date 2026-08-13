<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class MessageBoard extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.message-board.label');
    }

    public static function description(): string
    {
        return __('features.message-board.description');
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Conversation;
    }
}
