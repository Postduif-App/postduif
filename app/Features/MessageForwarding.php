<?php

namespace App\Features;

class MessageForwarding extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Berichten doorsturen';
    }

    public static function description(): string
    {
        return 'Een bericht uit het ene kanaal in het andere plaatsen, met de herkomst erbij.';
    }
}
