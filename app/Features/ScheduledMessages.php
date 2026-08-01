<?php

namespace App\Features;

class ScheduledMessages extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Geplande berichten';
    }

    public static function description(): string
    {
        return 'Leden kunnen een bericht klaarzetten dat later vanzelf verstuurd wordt.';
    }
}
