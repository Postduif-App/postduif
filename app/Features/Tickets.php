<?php

namespace App\Features;

class Tickets extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Tickets';
    }

    public static function description(): string
    {
        return 'Kanalen kunnen een ticketlijst voeren voor werk dat nog openstaat.';
    }
}
