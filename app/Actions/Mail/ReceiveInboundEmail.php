<?php

namespace App\Actions\Mail;

use App\Actions\Tickets\AnnounceTicket;
use App\Actions\Tickets\RecordTicketEvent;
use App\Enums\TicketEventType;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Features\Tickets as TicketsFeature;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\WorkspaceMailSettings;
use App\Support\InboundEmail;
use Illuminate\Support\Facades\DB;

class ReceiveInboundEmail
{
    public function __construct(
        private readonly RecordTicketEvent $recordTicketEvent,
        private readonly AnnounceTicket $announceTicket,
    ) {}

    /**
     * Turn a delivery into a ticket, or into a reply on the one it answers.
     *
     * Deliberately not two actions. Which of the two a mail is depends on the
     * mail itself rather than on the endpoint it came in on, and a caller that
     * had to work that out first would be the second place the threading rules
     * live — the first place they got out of step with this one.
     *
     * Returns null when there is nothing to be done: a workspace that has since
     * switched tickets off, a channel that was deleted, a mail with no sender.
     * Null rather than an exception, because none of those are the provider's
     * fault and a 500 back at them buys a retry that will fail the same way for
     * the next three days.
     */
    public function handle(WorkspaceMailSettings $settings, InboundEmail $email): Ticket|TicketComment|null
    {
        if (! $settings->receivesMail() || $email->from === '') {
            return null;
        }

        $channel = $settings->inboundChannel;

        /*
         * The feature and the channel's own policy, asked here rather than
         * trusted from the moment the setting was saved. A workspace can switch
         * tickets off, and a channel can stop keeping them, months after
         * somebody pointed a mail domain at it.
         */
        if ($channel === null || $channel->archived_at !== null) {
            return null;
        }

        if (! $settings->workspace->hasFeature(TicketsFeature::class) || ! $channel->hasTickets()) {
            return null;
        }

        $existing = $this->ticketAnsweredBy($settings, $email);

        return $existing === null
            ? $this->open($settings, $email)
            : $this->reply($existing, $email);
    }

    /**
     * The ticket this mail is a reply to, if it is one.
     *
     * Two ways of finding it, in this order and for a reason. The +t<number>
     * tag in the address is the reliable one: it is put there by us and copied
     * back by every mail client without being understood. The message ids are
     * the fallback for a reply that was written to the plain address — correct
     * when it works, and quietly absent in clients that drop the headers.
     *
     * Both are checked against this workspace's own tickets. A number in an
     * address is a guessable thing, and without that scope somebody could
     * append their sentence to a stranger's ticket by writing to their own
     * workspace's letterbox.
     */
    private function ticketAnsweredBy(WorkspaceMailSettings $settings, InboundEmail $email): ?Ticket
    {
        $tag = $email->replyTag();

        if ($tag !== null) {
            $tagged = Ticket::query()
                ->where('workspace_id', $settings->workspace_id)
                ->where('number', $tag)
                ->first();

            if ($tagged !== null) {
                return $tagged;
            }
        }

        if ($email->references === []) {
            return null;
        }

        return Ticket::query()
            ->where('workspace_id', $settings->workspace_id)
            ->where(fn ($query) => $query
                ->whereIn('mail_message_id', $email->references)
                ->orWhereIn('id', TicketComment::query()
                    ->whereIn('mail_message_id', $email->references)
                    ->select('ticket_id')))
            ->first();
    }

    /**
     * A mail nobody has seen before: a new ticket in the inbound channel.
     *
     * The same transaction CreateTicket uses, and for the same reason — a
     * number claimed by a ticket that then failed to save is a gap in the board
     * that reads as deleted work. Not routed through that action, though: it
     * takes a User and there is not one here, and widening it to accept an
     * address would push "who opened this" into every caller that has a plain
     * answer.
     */
    private function open(WorkspaceMailSettings $settings, InboundEmail $email): Ticket
    {
        $channel = $settings->inboundChannel;

        return DB::transaction(function () use ($settings, $channel, $email): Ticket {
            $ticket = Ticket::create([
                'workspace_id' => $settings->workspace_id,
                'channel_id' => $channel->id,
                'number' => $settings->workspace->claimTicketNumber(),
                'title' => $email->ticketTitle(),
                'body' => $email->body,
                'sender_email' => $email->from,
                'sender_name' => $email->fromName,
                'mail_message_id' => $email->messageId,
            ]);

            // No actor: nobody inside did this. RecordTicketEvent has taken a
            // nullable actor since webhooks existed, which is the same case.
            $this->recordTicketEvent->handle($ticket, TicketEventType::Created);

            $this->announceTicket->opened($ticket);

            TicketUpdated::dispatch($ticket);

            return $ticket;
        });
    }

    /**
     * A reply on a ticket that is already open.
     *
     * Reopened if it had been closed, which is what a customer writing again
     * means whatever the queue thought. Not routed through CommentOnTicket for
     * the same reason as above, and one more: that action marks a first
     * response, and a customer answering their own ticket is not one.
     */
    private function reply(Ticket $ticket, InboundEmail $email): TicketComment
    {
        return DB::transaction(function () use ($ticket, $email): TicketComment {
            $comment = TicketComment::create([
                'ticket_id' => $ticket->id,
                'body' => $email->body,
                'sender_email' => $email->from,
                'sender_name' => $email->fromName,
                'mail_message_id' => $email->messageId,
            ]);

            /*
             * A comment is not an event anywhere else in this application —
             * CommentOnTicket records none — and it is not one here either.
             * Reopening is, and it goes down as the status change it is, with
             * the same payload the ticket screen already knows how to draw.
             */
            if ($ticket->status->isClosed()) {
                $from = $ticket->status;

                $ticket->forceFill([
                    'status' => TicketStatus::Open,
                    // Cleared with the status, or every report that reads this
                    // column alone would still count the ticket as finished.
                    'closed_at' => null,
                ])->save();

                $this->recordTicketEvent->handle($ticket, TicketEventType::StatusChanged, null, [
                    'from' => $from->value,
                    'to' => TicketStatus::Open->value,
                ]);

                $this->announceTicket->statusChanged($ticket, $from, TicketStatus::Open);
            }

            TicketUpdated::dispatch($ticket);

            return $comment;
        });
    }
}
