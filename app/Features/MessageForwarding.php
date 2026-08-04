<?php

namespace App\Features;

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
}
