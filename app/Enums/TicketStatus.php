<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a ticket stands.
 *
 * Deliberately five, and no more. A list a customer has to interpret is worse
 * than no list at all, and every status somebody has to think twice about ends
 * up unused or used wrong.
 *
 * Waiting is the one that earns its place: without it "open" covers both the
 * tickets a customer is waiting on and the ones waiting on the customer, and
 * those are the two things they most need to tell apart.
 */
enum TicketStatus: string implements HasLabel
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Waiting = 'waiting';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * Whether this still counts as outstanding.
     *
     * Resolved falls outside it: the work is done and only confirmation is
     * missing, so counting it as open would keep telling a customer they have
     * something to chase when they do not.
     */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), strict: true);
    }

    public function isClosed(): bool
    {
        return $this === self::Closed;
    }

    /**
     * The query-side twin of isOpen(), for the places that filter a list rather
     * than judge a single ticket.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Open, self::InProgress, self::Waiting];
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_map(fn (self $status): string => $status->value, self::open());
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => __('enums.ticket-status.label.Open'),
            self::InProgress => __('enums.ticket-status.label.InProgress'),
            self::Waiting => __('enums.ticket-status.label.Waiting'),
            self::Resolved => __('enums.ticket-status.label.Resolved'),
            self::Closed => __('enums.ticket-status.label.Closed'),
        };
    }

    /**
     * Shown under the label where a status is chosen, because the name alone
     * does not say who is expected to act next — which is the only thing anyone
     * actually reads a status for.
     */
    public function description(): string
    {
        return match ($this) {
            self::Open => __('enums.ticket-status.description.Open'),
            self::InProgress => __('enums.ticket-status.description.InProgress'),
            self::Waiting => __('enums.ticket-status.description.Waiting'),
            self::Resolved => __('enums.ticket-status.description.Resolved'),
            self::Closed => __('enums.ticket-status.description.Closed'),
        };
    }
}
