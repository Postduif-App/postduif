<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowRecordType;
use App\Features\Tickets;
use App\Features\WorkspaceFeature;

/**
 * Read a ticket again.
 *
 * The one people reach for after a Delay: status, is_overdue and answered all
 * move while a workflow is waiting, and a condition written against the
 * trigger's copy of them is comparing against the day the ticket was opened.
 */
class ReadTicket extends ReadRecord
{
    public static function label(): string
    {
        return __('workflows.actions.read-ticket.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.read-ticket.description');
    }

    protected static function type(): WorkflowRecordType
    {
        return WorkflowRecordType::Ticket;
    }

    /** @return class-string<WorkspaceFeature> */
    protected static function feature(): string
    {
        return Tickets::class;
    }

    protected static function fieldLabel(): string
    {
        return __('workflows.actions.fields.ticket');
    }

    protected static function fieldHint(): string
    {
        return __('workflows.actions.fields.ticket_hint');
    }
}
