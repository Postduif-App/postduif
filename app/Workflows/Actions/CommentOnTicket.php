<?php

namespace App\Workflows\Actions;

use App\Actions\Tickets\CommentOnTicket as SayOnTicket;
use App\Enums\WorkflowRecordType;
use App\Features\Tickets;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Say something on a ticket.
 *
 * The acknowledgement, mostly: a customer who raised a ticket at midnight
 * should not have to wonder whether it arrived. Also the audit line — "dit is
 * automatisch naar de leverancier gestuurd" — which belongs on the ticket
 * rather than in a channel somebody has to go and find.
 *
 * In the name of the workflow's owner, not as a bot. A comment on a ticket is
 * part of a conversation with somebody, and an answer from nobody is worse than
 * no answer: the customer cannot reply to it and nobody is accountable for it.
 *
 * One consequence worth knowing about: the ordinary action stamps
 * first_responded_at when the comment is not the reporter's own, so an
 * automatic acknowledgement counts as the ticket having been answered. That is
 * how the board reads it too, and it is the reason to think twice about a
 * workflow that says nothing more than "we hebben het ontvangen" — it stops the
 * stale sweep noticing that nobody has actually looked.
 */
class CommentOnTicket extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly SayOnTicket $comment) {}

    public static function label(): string
    {
        return __('workflows.actions.comment-on-ticket.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.comment-on-ticket.description');
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
            WorkflowField::longText(
                'body',
                __('workflows.actions.fields.body'),
                __('workflows.actions.fields.body_hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'ticket.id' => __('workflows.provides.ticket.id'),
            'ticket.number' => __('workflows.provides.ticket.number'),
            'comment.id' => __('workflows.provides.ticket.comment_id'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Tickets::class)) {
            throw new RuntimeException(__('workflows.errors.tickets_off'));
        }

        $ticket = $this->ticket($context);
        $author = $this->actor($context);

        /*
         * comment rather than manage: saying something is open to whoever is in
         * the channel, guests included — a customer answering on their own
         * ticket is the ordinary case, and a workflow owned by one should not
         * be held to a stricter rule than they are.
         */
        if ($author->cannot('comment', $ticket)) {
            throw new RuntimeException(__('workflows.errors.may_not_comment_on_ticket', [
                'number' => (string) $ticket->number,
            ]));
        }

        $body = trim((string) $context->setting('body', ''));

        /*
         * An empty comment after the variables were filled in. Said plainly,
         * because a blank line on a ticket is worse than silence: it looks like
         * an answer to whoever is waiting for one.
         */
        if ($body === '') {
            throw new RuntimeException(__('workflows.errors.empty_comment'));
        }

        $comment = $this->comment->handle($ticket, $author, $body);

        return [
            'ticket' => ['id' => $ticket->id, 'number' => $ticket->number],
            'comment' => ['id' => $comment->id],
        ];
    }
}
