<?php

namespace App\Features;

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
}
