<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class Forms extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.forms.label');
    }

    public static function description(): string
    {
        return __('features.forms.description');
    }

    /*
     * Off by default, with Transfers and SecretRequests rather than with Polls.
     *
     * A poll is a message with buttons on it and reaches nobody outside. A form
     * can be handed to the world as a link and collects what people type into
     * it, which is a thing a workspace should switch on deliberately rather
     * than discover it already had.
     */
    public static function default(): bool
    {
        return false;
    }

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Work;
    }
}
