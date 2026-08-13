<?php

namespace App\Enums;

use App\Features\Contracts as ContractsFeature;
use App\Features\Polls as PollsFeature;
use App\Features\Tickets as TicketsFeature;
use App\Features\Timeclock as TimeclockFeature;
use App\Features\WorkspaceFeature;
use App\Models\Contract;
use App\Models\Poll;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Workflows\RecordSnapshot;

/**
 * The lists a loop can walk.
 *
 * A named handful rather than "write your own query", and that is the whole
 * design decision here. A workflow builder that could express an arbitrary
 * query would be a small database language in a form on a settings screen —
 * something to learn, something to get wrong, and a way to make a workspace's
 * own server do arbitrary work on a timer.
 *
 * What these have in common is that they are all "the ones that are still
 * outstanding": the question somebody has at midnight, or on a Monday morning,
 * about a pile of things nobody has got to. That is what a loop is for. A list
 * of everything ever created is not a list anybody wants a workflow to walk.
 *
 * Every one of them is scoped to a single workspace, in the query rather than
 * afterwards. A loop is the one place where "one row too many" is not a bug you
 * notice — it is fifty messages sent to the wrong company.
 */
enum WorkflowListSource: string
{
    /** Shifts still running: the laptop shut at five with the button untouched. */
    case RunningShifts = 'running-shifts';

    /** Tickets that are open and past their due date. */
    case OverdueTickets = 'overdue-tickets';

    /** Every ticket still open, due date or not. */
    case OpenTickets = 'open-tickets';

    /** Contracts that are out and have not come back. */
    case OutstandingContracts = 'outstanding-contracts';

    /** Polls that still take votes. */
    case OpenPolls = 'open-polls';

    /**
     * How many rows one loop may ever walk.
     *
     * Not a performance number — it is the blast radius. A loop that sends a
     * message per row and finds four hundred rows sends four hundred messages,
     * and the person who wrote it meant "the handful that are overdue". Where
     * the list is longer than this the loop walks the first fifty and says so
     * out loud, which is a strange enough result that somebody goes and looks.
     */
    public const MAX_ITEMS = 50;

    /**
     * The rows, each as the fragment of context the body reads.
     *
     * Shaped by RecordSnapshot wherever the row is a record, so a loop body
     * writes {{ item.ticket.title }} in exactly the spelling a trigger would
     * have used for the same fact. The shift list is the odd one out and says
     * so in its own arm: what a workflow does with a running shift is about the
     * person, not about the row.
     *
     * @return list<array<string, mixed>>
     */
    public function items(Workspace $workspace): array
    {
        return array_values(match ($this) {
            self::RunningShifts => TimeEntry::query()
                ->where('workspace_id', $workspace->id)
                ->running()
                ->with('user:id,name')
                ->orderBy('id')
                ->limit(self::MAX_ITEMS)
                ->get()
                ->map(fn (TimeEntry $entry): array => [
                    'user' => ['id' => $entry->user_id, 'name' => $entry->user?->name],
                    'shift' => [
                        'id' => $entry->id,
                        'started_at' => $entry->started_at->toIso8601String(),
                        // Whole hours, so a condition comparing against 12 does
                        // not have to reason about minutes.
                        'hours' => (int) $entry->started_at->diffInHours(now()),
                    ],
                ])
                ->all(),

            self::OverdueTickets => $this->tickets($workspace, overdueOnly: true),
            self::OpenTickets => $this->tickets($workspace, overdueOnly: false),

            self::OutstandingContracts => Contract::query()
                ->where('workspace_id', $workspace->id)
                ->where('is_template', false)
                ->where('status', ContractStatus::Sent->value)
                ->orderBy('id')
                ->limit(self::MAX_ITEMS)
                ->get()
                ->map(fn (Contract $contract): array => RecordSnapshot::contract($contract))
                ->all(),

            self::OpenPolls => Poll::query()
                ->where('workspace_id', $workspace->id)
                ->open()
                ->orderBy('id')
                ->limit(self::MAX_ITEMS)
                ->get()
                ->map(fn (Poll $poll): array => RecordSnapshot::poll($poll))
                ->all(),
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tickets(Workspace $workspace, bool $overdueOnly): array
    {
        return array_values(Ticket::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', TicketStatus::openValues())
            ->when($overdueOnly, fn ($query) => $query
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()))
            ->orderBy('id')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (Ticket $ticket): array => RecordSnapshot::ticket($ticket))
            ->all());
    }

    /**
     * Which switch this list lives behind, so a loop over it is refused where
     * that part of the workspace is off.
     *
     * @return class-string<WorkspaceFeature>
     */
    public function feature(): string
    {
        return match ($this) {
            self::RunningShifts => TimeclockFeature::class,
            self::OverdueTickets, self::OpenTickets => TicketsFeature::class,
            self::OutstandingContracts => ContractsFeature::class,
            self::OpenPolls => PollsFeature::class,
        };
    }

    public function label(): string
    {
        return __("enums.workflow-list-source.label.{$this->name}");
    }

    /**
     * What one row holds, as path => what it is.
     *
     * The half that makes a loop writable rather than guessable. Without it the
     * builder can say "{{ item.* }}" and nothing more, and somebody writing a
     * message inside a loop is left guessing at item.ticket.title until they
     * have run it once and read the run screen.
     *
     * Taken from RecordSnapshot wherever the row is a record, so the paths the
     * picker offers inside a loop are spelled exactly the way the same facts are
     * spelled everywhere else in a workflow.
     *
     * @return array<string, string>
     */
    public function provides(): array
    {
        $paths = match ($this) {
            self::RunningShifts => [
                'user.id' => __('workflows.provides.list.user_id'),
                'user.name' => __('workflows.provides.list.user_name'),
                'shift.id' => __('workflows.provides.list.shift_id'),
                'shift.started_at' => __('workflows.provides.list.shift_started_at'),
                'shift.hours' => __('workflows.provides.list.shift_hours'),
            ],
            self::OverdueTickets, self::OpenTickets => RecordSnapshot::paths(WorkflowRecordType::Ticket),
            self::OutstandingContracts => RecordSnapshot::paths(WorkflowRecordType::Contract),
            self::OpenPolls => RecordSnapshot::paths(WorkflowRecordType::Poll),
        };

        return collect($paths)
            ->mapWithKeys(fn (string $what, string $path): array => ["item.{$path}" => $what])
            // Which row of the list this is, counting from one — for a message
            // that wants to say "3 van de 12" without a step to count with.
            ->put('item.index', __('workflows.provides.list.index'))
            ->all();
    }

    /**
     * The whole list as the builder wants it: what it reads as, and what a row
     * of it holds.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $source): array => [
                $source->value => [
                    'label' => $source->label(),
                    'provides' => $source->provides(),
                ],
            ])
            ->all();
    }
}
