<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class Huddles extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.huddles.label');
    }

    public static function description(): string
    {
        return __('features.huddles.description');
    }

    /**
     * Off until somebody says otherwise, and for a different reason than
     * Transfers: this one is not about what leaves the workspace but about
     * whether it works at all. Audio between two browsers on different networks
     * needs a relay to fall back on — see the TURN configuration — and a
     * workspace that has not arranged one would be handing its people a button
     * that connects for some of them and silently does not for the rest.
     */
    public static function default(): bool
    {
        return false;
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Conversation;
    }
}
