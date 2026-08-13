<?php

namespace App\Workflows\Actions;

use App\Actions\Tickets\UpdateTicket as ChangeTicket;
use App\Enums\WorkflowRecordType;
use App\Features\Tickets;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Hand a ticket to somebody, or take it off them.
 *
 * Apart from update-ticket, which moves the other three fields, because this
 * one is about a person and people are what nobody wants done to them by
 * surprise. Keeping it separate means "wijs elk urgent ticket toe aan de
 * teamlead" is a decision somebody made on its own, in a step that says so.
 *
 * Leaving the person empty takes the ticket off whoever had it — the same thing
 * the board does with the unassign button. That reading rather than a second
 * "haal weg"-box: they are one column, and two ways to set one column is one
 * too many.
 *
 * What it cannot yet do is assign to whoever the trigger named — the person
 * picker takes no variable. See pcom-ybal.19; the day that lands, this action
 * gets it without changing.
 */
class AssignTicket extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly ChangeTicket $change) {}

    public static function label(): string
    {
        return __('workflows.actions.assign-ticket.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.assign-ticket.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::record(
                'ticket_id',
                WorkflowRecordType::Ticket,
                __('workflows.actions.fields.ticket'),
                __('workflows.actions.fields.ticket_hint'),
            ),
            WorkflowField::member(
                'user_id',
                __('workflows.actions.assign-ticket.person.label'),
                __('workflows.actions.assign-ticket.person.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'ticket.id' => __('workflows.provides.ticket.id'),
            'ticket.number' => __('workflows.provides.ticket.number'),
            'ticket.status' => __('workflows.provides.ticket.status'),
            'assignee.id' => __('workflows.provides.ticket.assignee_id'),
            'assignee.name' => __('workflows.provides.ticket.assignee_name'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Tickets::class)) {
            throw new RuntimeException(__('workflows.errors.tickets_off'));
        }

        $ticket = $this->ticket($context);
        $actor = $this->actor($context);

        if ($actor->cannot('manage', $ticket)) {
            throw new RuntimeException(__('workflows.errors.may_not_manage_ticket', [
                'number' => (string) $ticket->number,
            ]));
        }

        $assignee = blank($context->setting('user_id')) ? null : $this->member($context);

        /*
         * That the person can actually see the ticket, which the picker does
         * not guarantee: it offers everybody in the workspace, and a ticket
         * lives in a channel not everybody is in. Handing work to somebody who
         * cannot open it is worse than not handing it over at all — they would
         * be told they have it and find nothing there.
         */
        if ($assignee !== null && $assignee->cannot('view', $ticket)) {
            throw new RuntimeException(__('workflows.errors.assignee_cannot_see_ticket', [
                'name' => $assignee->name,
            ]));
        }

        $ticket = $this->change->assign($ticket, $assignee, $actor);

        return [
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'status' => $ticket->fresh()?->status->value,
            ],
            'assignee' => ['id' => $assignee?->id, 'name' => $assignee?->name],
        ];
    }
}
