<?php

namespace App\Workflows\Triggers;

/**
 * A ticket was opened.
 *
 * Its own trigger rather than a case in the changed one, although the event
 * behind them is the same: this is the only ticket moment with nothing to
 * compare against, and a workflow written here would be offered
 * {{ trigger.change.from }} for a ticket that came from nowhere.
 *
 * It is also the one people reach for first — acknowledge it, put it somewhere,
 * open the day with a list — and burying the commonest trigger inside a
 * dropdown on a general one is how a builder starts feeling clever.
 */
class TicketCreatedTrigger extends TicketTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.ticket-created.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.ticket-created.description');
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::ticketProvides(),
            ...static::actorProvides(),
        ];
    }
}
