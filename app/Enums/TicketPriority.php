<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How urgent a ticket is.
 *
 * Only members set this. A customer marking their own ticket urgent says
 * nothing you did not already know — everyone's own problem is urgent — so the
 * field only starts meaning something once one person weighs all the tickets in
 * a channel against each other.
 */
enum TicketPriority: string implements HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * For ordering a board, highest first.
     *
     * A number of its own rather than the order the cases are written in: they
     * read low to high, and a board wants exactly the opposite.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Laag',
            self::Normal => 'Normaal',
            self::High => 'Hoog',
            self::Urgent => 'Urgent',
        };
    }
}
