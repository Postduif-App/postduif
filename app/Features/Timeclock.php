<?php

namespace App\Features;

class Timeclock extends WorkspaceFeature
{
    public static function label(): string
    {
        return __('features.timeclock.label');
    }

    public static function description(): string
    {
        return __('features.timeclock.description');
    }

    /*
     * Off by default, and for a different reason than Forms and Transfers.
     *
     * Those are off because they let the outside in. This one lets nobody
     * anywhere — it is off because of what it records. Every other switch here
     * decides whether the product offers something; this one decides whether
     * the product starts keeping account of what a person does with their day.
     * A workspace should arrive at that on purpose, having said so out loud,
     * rather than find the clock already running.
     */
    public static function default(): bool
    {
        return false;
    }
}
