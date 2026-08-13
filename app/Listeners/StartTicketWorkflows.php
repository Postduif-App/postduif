<?php

namespace App\Listeners;

use App\Actions\Workflows\StartMatchingWorkflows;
use App\Enums\TicketEventType;
use App\Events\TicketChanged;
use App\Events\TicketCommented;
use App\Events\TicketWentStale;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use App\Models\Workflow;
use App\Workflows\Triggers\TicketChangedTrigger;
use App\Workflows\Triggers\TicketCommentedTrigger;
use App\Workflows\Triggers\TicketCreatedTrigger;
use App\Workflows\Triggers\TicketStaleTrigger;
use App\Workflows\WorkflowTrigger;

/**
 * Set off the workflows that were waiting for something to happen to a ticket.
 *
 * One class for three events and four triggers, the way the contracts listener
 * is one for eight — the filtering and most of the payload are the same
 * whichever of them fired, and copies of that would be places to forget the
 * channel check.
 *
 * The fork worth noticing is at the top of handleChanged: one event carries
 * both "a ticket was opened" and "something about it moved", because both write
 * a line in the timeline and RecordTicketEvent announces every line. Which
 * trigger that becomes is decided here rather than in the event, which has no
 * business knowing how the builder is laid out.
 */
class StartTicketWorkflows
{
    public function __construct(private readonly StartMatchingWorkflows $startWorkflows) {}

    public function handleChanged(TicketChanged $event): void
    {
        $ticket = $this->ticket($event->ticketId);

        if ($ticket === null) {
            return;
        }

        $actor = $event->actorId === null ? null : User::find($event->actorId);

        if ($event->type === TicketEventType::Created) {
            $this->start($ticket, TicketCreatedTrigger::class, $this->context($ticket, $actor));

            return;
        }

        $kind = $this->kindOf($event->type);

        /*
         * A timeline entry this trigger has no name for. Unassigning is the one
         * today — it is folded into the assignee kind below — and anything a
         * later version of the ticket board records would land here too, where
         * it does nothing rather than starting workflows nobody wrote.
         */
        if ($kind === null) {
            return;
        }

        $this->start($ticket, TicketChangedTrigger::class, [
            ...$this->context($ticket, $actor),
            'change' => [
                'kind' => $kind,
                'from' => $this->side($event->payload, 'from', $event->type),
                'to' => $this->side($event->payload, 'to', $event->type),
            ],
        ], $kind);
    }

    public function handleCommented(TicketCommented $event): void
    {
        $ticket = $this->ticket($event->ticketId);
        $comment = TicketComment::find($event->commentId);

        if ($ticket === null || $comment === null) {
            return;
        }

        $author = User::find($event->authorId);

        $this->start($ticket, TicketCommentedTrigger::class, [
            ...$this->context($ticket, $author),
            'comment' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'is_first_response' => $event->wasFirstResponse,
            ],
            'author' => ['id' => $author?->id, 'name' => $author?->name],
        ]);
    }

    public function handleStale(TicketWentStale $event): void
    {
        $ticket = $this->ticket($event->ticketId);

        if ($ticket === null) {
            return;
        }

        $this->start($ticket, TicketStaleTrigger::class, [
            ...$this->context($ticket, null),
            'stale' => ['reason' => $event->reason],
        ], $event->reason);
    }

    /**
     * @param  class-string<WorkflowTrigger>  $trigger
     * @param  array<string, mixed>  $context
     * @param  string|null  $choice  What the workflow's own dropdown has to say, where it has one.
     */
    private function start(Ticket $ticket, string $trigger, array $context, ?string $choice = null): void
    {
        $this->startWorkflows->handle(
            $ticket->channel?->workspace,
            $trigger,
            fn (Workflow $workflow): ?array => $this->matches($workflow, $ticket, $choice)
                ? $context
                : null,
        );
    }

    /**
     * Whether this workflow was written about this ticket, and this kind of
     * happening.
     *
     * The channel is the filter that matters: a workspace's tickets are not one
     * queue, and a workflow written for the customer channel has no business
     * firing on the internal board.
     */
    private function matches(Workflow $workflow, Ticket $ticket, ?string $choice): bool
    {
        $channelId = $workflow->triggerSetting('channel_id');

        // Loosely, because the id came out of a JSON column where 7 may be "7".
        // A channel named rather than picked is no filter — see the field hint.
        if (filled($channelId) && ctype_digit((string) $channelId)
            && (int) $channelId !== $ticket->channel_id) {
            return false;
        }

        if ($choice === null) {
            return true;
        }

        /*
         * "any" is a real answer rather than an empty one, and an unset setting
         * is read as it too — a workflow written before the dropdown existed
         * should go on doing what it did.
         */
        $wanted = (string) $workflow->triggerSetting($this->settingFor($choice), 'any');

        return $wanted === 'any' || $wanted === $choice;
    }

    /**
     * Which box on the trigger holds the choice.
     *
     * Two triggers, two names, and neither is worth a second method: the stale
     * one asks about a reason and the changed one about a kind, and they never
     * meet.
     */
    private function settingFor(string $choice): string
    {
        return in_array($choice, ['overdue', 'unanswered'], true) ? 'reason' : 'kind';
    }

    /**
     * The dropdown's word for a timeline entry, or null when it has none.
     *
     * Unassigning is folded into "assignee": taking a ticket off somebody is
     * the same event to anybody waiting on it, and change.to is empty, which
     * says which way it went without a second option in the list.
     */
    private function kindOf(TicketEventType $type): ?string
    {
        return match ($type) {
            TicketEventType::StatusChanged => 'status',
            TicketEventType::PriorityChanged => 'priority',
            TicketEventType::Assigned, TicketEventType::Unassigned => 'assignee',
            TicketEventType::DueDateChanged => 'due',
            TicketEventType::Created => null,
        };
    }

    /**
     * One side of the change, as a string a condition can compare.
     *
     * The timeline stores what each kind of entry needs and no more, which is
     * why this is not a plain array read: an assignment records only who it
     * went to, so "van" is empty, and an unassignment records nothing at all.
     *
     * @param  array<string, mixed>  $payload
     */
    private function side(array $payload, string $which, TicketEventType $type): string
    {
        if ($type === TicketEventType::Assigned) {
            return $which === 'to' ? (string) ($payload['assignee'] ?? '') : '';
        }

        if ($type === TicketEventType::Unassigned) {
            return '';
        }

        return (string) ($payload[$which] ?? '');
    }

    private function ticket(int $id): ?Ticket
    {
        return Ticket::query()
            ->with(['channel.workspace', 'assignee', 'opener'])
            ->find($id);
    }

    /**
     * What every ticket trigger promises, as TicketTrigger::ticketProvides
     * describes it.
     *
     * hours_open and is_overdue are worked out here for the reason the whole
     * epic keeps running into: a condition can compare a number but cannot
     * produce one.
     *
     * @return array<string, mixed>
     */
    private function context(Ticket $ticket, ?User $actor): array
    {
        return [
            'ticket' => [
                'id' => $ticket->id,
                'number' => $ticket->number,
                'title' => $ticket->title,
                'body' => $ticket->body,
                'status' => $ticket->status->value,
                'priority' => $ticket->priority->value,
                'due_at' => $ticket->due_at?->toIso8601String(),
                // Whole hours: a condition comparing against 24 should not have
                // to reason about minutes.
                'hours_open' => (int) $ticket->created_at?->diffInHours(now()),
                'is_overdue' => $ticket->due_at !== null && $ticket->due_at->isPast(),
                'has_assignee' => $ticket->assigned_to !== null,
                // Whether anybody has answered the person who raised it, which
                // is the number a customer channel is actually judged on.
                'answered' => $ticket->first_responded_at !== null,
            ],
            'assignee' => ['id' => $ticket->assigned_to, 'name' => $ticket->assignee?->name],
            'reporter' => ['id' => $ticket->opened_by, 'name' => $ticket->openedByName()],
            'channel' => ['id' => $ticket->channel_id, 'name' => $ticket->channel?->name],
            /*
             * Empty for the scheduler and for mail from outside. Left empty
             * rather than filled with a stand-in, so a half-written sentence
             * comes out visibly incomplete instead of naming somebody who does
             * not exist.
             */
            'actor' => ['id' => $actor?->id, 'name' => $actor?->name],
        ];
    }
}
