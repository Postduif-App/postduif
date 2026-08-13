<?php

namespace App\Workflows\Actions;

use App\Actions\Tickets\UpdateTicket as ChangeTicket;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\WorkflowRecordType;
use App\Features\Tickets;
use App\Models\Ticket;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Move a ticket: its status, its priority, its deadline.
 *
 * One action for the three rather than three actions, because they are one
 * decision in practice — "escaleren" is urgent *and* in behandeling *and* due
 * tomorrow, and three steps to say that is three chances for a workflow to be
 * half-applied. Everything left empty is left alone, so the action is also the
 * simple one: only the status filled in, and it does only that.
 *
 * Closing a ticket is this action with the status set to Gesloten. There is no
 * separate close-ticket, which was considered: a second way to do a thing the
 * dropdown already does is a second thing to explain, and the dropdown is
 * where somebody looks anyway.
 *
 * Every move goes through the ordinary UpdateTicket, so the timeline entry, the
 * announcement in the channel and the broadcast all happen exactly as they do
 * when a person does it — and a workflow's change is not a second kind of
 * change the board has to know about.
 */
class UpdateTicket extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly ChangeTicket $change) {}

    public static function label(): string
    {
        return __('workflows.actions.update-ticket.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.update-ticket.description');
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
            WorkflowField::choice(
                'status',
                __('workflows.actions.update-ticket.status.label'),
                self::choices(TicketStatus::cases()),
                __('workflows.actions.update-ticket.leave_alone'),
                required: false,
            ),
            WorkflowField::choice(
                'priority',
                __('workflows.actions.update-ticket.priority.label'),
                self::choices(TicketPriority::cases()),
                __('workflows.actions.update-ticket.leave_alone'),
                required: false,
            ),
            /*
             * Days from now rather than a date. A workflow does not know when
             * it will run — that is half the point of the delay step — so
             * "over twee dagen" is the only deadline it can mean.
             */
            WorkflowField::number(
                'due_in_days',
                __('workflows.actions.update-ticket.due.label'),
                __('workflows.actions.update-ticket.due.hint'),
                required: false,
            ),
        ];
    }

    /**
     * An enum as a dropdown, keyed as it is stored.
     *
     * Neither of these enums has an options() of its own — the ticket board
     * builds its lists in the screen — so this is the one place that turns them
     * into a field, rather than a method added to two enums for one caller.
     *
     * @param  list<TicketStatus|TicketPriority>  $cases
     * @return array<string, string>
     */
    private static function choices(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'ticket.id' => __('workflows.provides.ticket.id'),
            'ticket.number' => __('workflows.provides.ticket.number'),
            'ticket.status' => __('workflows.provides.ticket.status'),
            'ticket.priority' => __('workflows.provides.ticket.priority'),
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

        /*
         * manage rather than update: this is how the work is being handled
         * rather than what it says, and that is not a guest's to change. Asked
         * of the workflow's owner at the moment of running — somebody taken out
         * of the channel takes their workflows' reach with them.
         */
        if ($actor->cannot('manage', $ticket)) {
            throw new RuntimeException(__('workflows.errors.may_not_manage_ticket', [
                'number' => (string) $ticket->number,
            ]));
        }

        $status = TicketStatus::tryFrom((string) $context->setting('status', ''));
        $priority = TicketPriority::tryFrom((string) $context->setting('priority', ''));
        $days = $context->setting('due_in_days');

        if ($status === null && $priority === null && blank($days)) {
            throw new RuntimeException(__('workflows.errors.nothing_to_change'));
        }

        if ($status !== null) {
            $ticket = $this->change->status($ticket, $status, $actor);
        }

        if ($priority !== null) {
            $ticket = $this->change->priority($ticket, $priority, $actor);
        }

        if (filled($days) && is_numeric($days)) {
            $ticket = $this->change->due($ticket, now()->addDays(max(0, (int) $days)), $actor);
        }

        return $this->describe($ticket);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Ticket $ticket): array
    {
        $ticket = $ticket->fresh() ?? $ticket;

        return [
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
            ],
        ];
    }
}
