<?php

namespace App\Features;

class InviteLinks extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Uitnodigingslinks';
    }

    public static function description(): string
    {
        return 'Meedoen via een deelbare link, naast een uitnodiging op naam.';
    }
}
