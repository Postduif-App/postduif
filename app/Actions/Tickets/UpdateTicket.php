<?php

namespace App\Actions\Tickets;

use App\Enums\TicketEventType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class UpdateTicket
{
    public function __construct(
        private readonly RecordTicketEvent $recordTicketEvent,
        private readonly AnnounceTicket $announceTicket,
    ) {}

    /**
     * Move a ticket to another status.
     *
     * Nothing happens when the status is already the one asked for. Two people
     * clicking "in behandeling" within a minute of each other is normal, and a
     * timeline that says so twice is a timeline people stop reading.
     */
    public function status(Ticket $ticket, TicketStatus $status, ?User $actor = null): Ticket
    {
        if ($ticket->status === $status) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $status, $actor): Ticket {
            $from = $ticket->status;

            $ticket->forceFill([
                'status' => $status,
                // Cleared on the way back out, not just set on the way in: a
                // reopened ticket that keeps its closing date would read as
                // finished in every report that only looks at this column.
                'closed_at' => $status->isClosed() ? now() : null,
            ])->save();

            $this->recordTicketEvent->handle($ticket, TicketEventType::StatusChanged, $actor, [
                'from' => $from->value,
                'to' => $status->value,
            ]);

            if ($status->isClosed()) {
                $this->announceTicket->closed($ticket);
            } else {
                $this->announceTicket->statusChanged($ticket, $from, $status);
            }

            TicketUpdated::dispatch($ticket);

            return $ticket;
        });
    }

    public function priority(Ticket $ticket, TicketPriority $priority, ?User $actor = null): Ticket
    {
        if ($ticket->priority === $priority) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $priority, $actor): Ticket {
            $from = $ticket->priority;

            $ticket->forceFill(['priority' => $priority])->save();

            $this->recordTicketEvent->handle($ticket, TicketEventType::PriorityChanged, $actor, [
                'from' => $from->value,
                'to' => $priority->value,
            ]);

            TicketUpdated::dispatch($ticket);

            return $ticket;
        });
    }

    /**
     * Hand a ticket to somebody, or take it off them.
     *
     * Picking up an unassigned ticket that is still Open also moves it to
     * InProgress. Somebody who claims a ticket has started on it, and leaving
     * the status behind means the board keeps showing it as untouched work.
     */
    public function assign(Ticket $ticket, ?User $assignee, ?User $actor = null): Ticket
    {
        if ($ticket->assigned_to === $assignee?->id) {
            return $ticket;
        }

        return DB::transaction(function () use ($ticket, $assignee, $actor): Ticket {
            $ticket->forceFill(['assigned_to' => $assignee?->id])->save();

            $this->recordTicketEvent->handle(
                $ticket,
                $assignee === null ? TicketEventType::Unassigned : TicketEventType::Assigned,
                $actor,
                $assignee === null ? [] : ['assignee' => $assignee->id],
            );

            if ($assignee !== null && $ticket->status === TicketStatus::Open) {
                $this->status($ticket, TicketStatus::InProgress, $actor);
            }

            TicketUpdated::dispatch($ticket);

            return $ticket;
        });
    }

    public function due(Ticket $ticket, ?DateTimeInterface $dueAt, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $dueAt, $actor): Ticket {
            $from = $ticket->due_at;

            $ticket->forceFill(['due_at' => $dueAt])->save();

            $this->recordTicketEvent->handle($ticket, TicketEventType::DueDateChanged, $actor, [
                'from' => $from?->toIso8601String(),
                'to' => $ticket->due_at?->toIso8601String(),
            ]);

            TicketUpdated::dispatch($ticket);

            return $ticket;
        });
    }

    /**
     * Correct what the ticket says it is about.
     *
     * No event: the title and description are the ticket itself rather than how
     * it is being handled, and a timeline full of wording changes buries the
     * three lines anyone opened it for.
     */
    public function describe(Ticket $ticket, string $title, string $body): Ticket
    {
        $ticket->forceFill(['title' => $title, 'body' => $body])->save();

        TicketUpdated::dispatch($ticket);

        return $ticket;
    }
}
