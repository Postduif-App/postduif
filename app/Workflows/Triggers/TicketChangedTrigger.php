<?php

namespace App\Workflows\Triggers;

use App\Enums\TicketEventType;
use App\Workflows\WorkflowField;

/**
 * The status, the priority, the assignee or the deadline moved.
 *
 * One trigger with a dropdown, where the contract slice has one per moment. The
 * rule is the same in both places and it is about the payload: these four all
 * carry a ticket, whoever did it, and a from and a to. A trigger apiece would
 * be four identical promises with four labels on them.
 *
 * What they change from and to is a string either way — a status value, a
 * priority value, a person's id, a date — which is why the paths are called
 * change.from and change.to rather than anything more specific. A condition
 * comparing change.to against "closed" reads perfectly well; a condition
 * comparing it against a date does too.
 */
class TicketChangedTrigger extends TicketTrigger
{
    /**
     * The kinds this trigger answers to, keyed as they are stored.
     *
     * Created is deliberately not among them — it has its own trigger, and
     * nothing to compare against.
     *
     * @return array<string, TicketEventType>
     */
    public const KINDS = [
        'status' => TicketEventType::StatusChanged,
        'priority' => TicketEventType::PriorityChanged,
        'assignee' => TicketEventType::Assigned,
        'due' => TicketEventType::DueDateChanged,
    ];

    public static function label(): string
    {
        return __('workflows.triggers.ticket-changed.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.ticket-changed.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            /*
             * Required, with "elke wijziging" as a real answer rather than as
             * the empty one — the same choice the timeclock trigger makes about
             * its direction, and for the same reason: a blank meaning
             * "everything" reads as a box somebody forgot.
             */
            WorkflowField::choice(
                'kind',
                __('workflows.triggers.ticket-changed.kind.label'),
                [
                    'any' => __('workflows.triggers.ticket-changed.kind.any'),
                    'status' => __('workflows.triggers.ticket-changed.kind.status'),
                    'priority' => __('workflows.triggers.ticket-changed.kind.priority'),
                    'assignee' => __('workflows.triggers.ticket-changed.kind.assignee'),
                    'due' => __('workflows.triggers.ticket-changed.kind.due'),
                ],
                __('workflows.triggers.ticket-changed.kind.hint'),
            ),
            ...parent::fields(),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            ...static::ticketProvides(),
            ...static::actorProvides(),
            'change.kind' => __('workflows.provides.ticket.change_kind'),
            'change.from' => __('workflows.provides.ticket.change_from'),
            'change.to' => __('workflows.provides.ticket.change_to'),
        ];
    }
}
