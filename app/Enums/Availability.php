<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What somebody has said about being reachable.
 *
 * Deliberately separate from presence. The presence channel knows whether a
 * browser is connected, which answers "is this tab open" — not "should I expect
 * an answer". Someone can be online with the laptop closed next to them and away
 * while reading every word. This is the half only the member themselves can
 * answer, so it is the half they get to set.
 */
enum Availability: string implements HasLabel
{
    case Available = 'available';
    case Away = 'away';
    case DoNotDisturb = 'do-not-disturb';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Available => __('enums.availability.label.Available'),
            self::Away => __('enums.availability.label.Away'),
            self::DoNotDisturb => __('enums.availability.label.DoNotDisturb'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Available => __('enums.availability.description.Available'),
            self::Away => __('enums.availability.description.Away'),
            self::DoNotDisturb => __('enums.availability.description.DoNotDisturb'),
        };
    }

    /**
     * Whether a notification may leave the building for this member.
     *
     * Only "do not disturb" stops it. Away says where somebody is, not that they
     * want silence — that is exactly the moment a push is worth something.
     */
    public function allowsNotifications(): bool
    {
        return $this !== self::DoNotDisturb;
    }
}
