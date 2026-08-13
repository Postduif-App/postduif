<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowField;

/**
 * A ticket has been left sitting.
 *
 * Called stale rather than overdue because two different things end up here,
 * and only one of them is about a date: a ticket past a deadline somebody set,
 * and a ticket nobody has answered at all. The second is the one a customer
 * notices first and the one no due date would have caught, because nobody got
 * round to setting one.
 *
 * It fires from the nightly sweep, which means it inherits that sweep's
 * cooldown: a workflow hung off this cannot go off every hour about the same
 * neglected ticket. That is a property worth relying on — see the event.
 */
class TicketStaleTrigger extends TicketTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.ticket-stale.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.ticket-stale.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::choice(
                'reason',
                __('workflows.triggers.ticket-stale.reason.label'),
                [
                    'any' => __('workflows.triggers.ticket-stale.reason.any'),
                    'overdue' => __('workflows.triggers.ticket-stale.reason.overdue'),
                    'unanswered' => __('workflows.triggers.ticket-stale.reason.unanswered'),
                ],
                __('workflows.triggers.ticket-stale.reason.hint'),
            ),
            ...parent::fields(),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::ticketProvides(),
            'stale.reason' => __('workflows.provides.ticket.stale_reason'),
        ];
    }
}
