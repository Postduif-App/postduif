<?php

namespace App\Features;

use App\Enums\FeatureGroup;

class Polls extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.polls.label');
    }

    public static function description(): string
    {
        return __('features.polls.description');
    }

    /*
     * On by default, unlike Transfers and SecretRequests. Those two are off
     * because they let something reach past the workspace — a download link for
     * the outside world, a store of other people's passwords. A poll does
     * neither: it is a message with buttons on it, and it belongs to the
     * product a workspace signed up for.
     */

    public static function group(): FeatureGroup
    {
        return FeatureGroup::Conversation;
    }
}
