<?php

namespace App\Features;

class Tickets extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.tickets.label');
    }

    public static function description(): string
    {
        return __('features.tickets.description');
    }
}
