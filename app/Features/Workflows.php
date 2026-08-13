<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class Workflows extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.workflows.label');
    }

    public static function description(): string
    {
        return __('features.workflows.description');
    }

    /**
     * Off to begin with, like AI access and transfers.
     *
     * Not because a workflow reaches outside the workspace by itself, but
     * because of what one can be pointed at: the actions here archive channels
     * and put people in them, and they run with the rights of whoever wrote the
     * workflow rather than of whoever set it off. A workspace should arrive at
     * that on purpose rather than find it already switched on.
     */
    public static function default(): bool
    {
        return false;
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Automation;
    }
}
