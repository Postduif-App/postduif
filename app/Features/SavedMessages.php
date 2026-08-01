<?php

namespace App\Features;

class SavedMessages extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Bewaarde berichten';
    }

    public static function description(): string
    {
        return 'Leden kunnen berichten bewaren en later in één lijst terugvinden.';
    }
}
