<?php

namespace App\Features;

class Webhooks extends WorkspaceFeature
{
    public static function label(): string
    {
        return 'Webhooks';
    }

    public static function description(): string
    {
        return 'Andere systemen mogen via een geheime URL in een kanaal posten.';
    }
}
