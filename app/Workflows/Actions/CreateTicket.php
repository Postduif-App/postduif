<?php

namespace App\Workflows\Actions;

use App\Actions\Tickets\CreateTicket as OpenTicket;
use App\Enums\TicketPriority;
use App\Features\Tickets;
use App\Models\Ticket;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Open a ticket in a channel.
 *
 * Through the ordinary CreateTicket action, for the reason SendChannelMessage
 * goes through SendMessage: a ticket opened by a workflow is a ticket, and a
 * second path into the board would be a second place for the number, the
 * announcement in the channel and the event log to be forgotten.
 *
 * Opened in the name of whoever wrote the workflow, unlike a message — which is
 * posted as a bot. The difference is that a ticket has an owner in a way a
 * message does not: somebody is answerable for it, it appears in their name on
 * the board, and "opened by nobody" is not a state the board can show. Their
 * rights are what decide whether it may be opened at all either way, so the
 * name on it matches the permission that allowed it.
 *
 * What this deliberately does not offer is assigning it to somebody. Handing a
 * colleague work automatically is a different act from recording that work
 * exists, and the one thing worse than a ticket nobody picked up is a ticket
 * somebody was given while they were on holiday.
 */
class CreateTicket extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly OpenTicket $openTicket) {}

    public static function label(): string
    {
        return __('workflows.actions.create-ticket.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.create-ticket.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::channel('channel_id', __('workflows.actions.fields.channel')),

            /*
             * Both take variables, which is most of the point: a ticket worth
             * opening automatically is one that quotes what set the workflow
             * off — the message, the person, the answers to a form.
             */
            WorkflowField::text(
                'title',
                __('workflows.actions.create-ticket.title.label'),
                __('workflows.actions.create-ticket.title.hint'),
            ),
            /*
             * Optional, unlike the title. What happened is sometimes the whole
             * of it, and forcing a second sentence out of a workflow means a
             * board full of descriptions that repeat their own heading.
             */
            WorkflowField::longText(
                'body',
                __('workflows.actions.create-ticket.body.label'),
                __('workflows.actions.fields.body_hint'),
                required: false,
            ),

            /*
             * Optional, and normal when it is left alone. A workflow that
             * marked everything urgent would be a board where nothing is.
             */
            WorkflowField::choice(
                'priority',
                __('workflows.actions.create-ticket.priority'),
                array_reduce(
                    TicketPriority::cases(),
                    function (array $options, TicketPriority $priority): array {
                        $options[$priority->value] = $priority->label();

                        return $options;
                    },
                    [],
                ),
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
            'channel.id' => __('workflows.provides.channel.id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        /*
         * The feature is asked about here rather than left to the register.
         * A workspace can switch tickets off long after somebody wrote this
         * step, and a run that quietly opened one anyway would put work on a
         * board nobody in that workspace can reach any more.
         */
        if (! $context->workspace()->hasFeature(Tickets::class)) {
            throw new RuntimeException(__('workflows.errors.tickets_off'));
        }

        $channel = $this->channel($context);
        $opener = $this->actor($context);

        $title = trim((string) $context->setting('title', ''));
        $body = trim((string) $context->setting('body', ''));

        /*
         * Checked at the moment of running, not when the workflow was written:
         * a channel's ticket rule can change, it can be archived, and the owner
         * can be taken out of it. See TicketPolicy::create, which weighs all
         * three.
         */
        if ($opener->cannot('create', [Ticket::class, $channel])) {
            throw new RuntimeException(__('workflows.errors.may_not_open_ticket', [
                'channel' => (string) $channel->name,
            ]));
        }

        /*
         * An empty title after the variables were filled in. Said plainly here,
         * because a ticket called "" sits on a board forever and tells nobody
         * what it was meant to be about.
         */
        if ($title === '') {
            throw new RuntimeException(__('workflows.errors.empty_ticket_title'));
        }

        $ticket = $this->openTicket->handle(
            channel: $channel,
            opener: $opener,
            title: $title,
            // A ticket may reasonably carry only a title — what happened is
            // sometimes the whole of it — so an empty description is allowed
            // where an empty title is not.
            body: $body,
            priority: $this->priority($context),
        );

        return [
            'ticket' => ['id' => $ticket->id, 'number' => $ticket->number],
            'channel' => ['id' => $channel->id],
        ];
    }

    /**
     * What was chosen, or normal.
     *
     * tryFrom rather than from: the setting came out of a JSON column that a
     * different version of this action may have written, and a priority nobody
     * recognises is not a reason to fail a run that is otherwise fine.
     */
    private function priority(WorkflowStepContext $context): TicketPriority
    {
        $chosen = $context->setting('priority');

        return TicketPriority::tryFrom((string) $chosen) ?? TicketPriority::Normal;
    }
}
