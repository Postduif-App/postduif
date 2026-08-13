<?php

namespace App\Mail;

use App\Models\TicketComment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The answer a colleague wrote, on its way back to whoever sent the mail.
 *
 * The other half of the letterbox. Post that arrives becomes a ticket and a
 * reply on it becomes a comment; without this, the person who wrote in sees
 * nothing of what was answered and the whole thing is a one-way street.
 *
 * Three headers do the work of keeping it a conversation, and each of them
 * answers a different failure:
 *
 * - Reply-To is the workspace's own address with +t<number> in it, which is
 *   what ReceiveInboundEmail reads first and the only one of the three that
 *   every mail client copies back without understanding it.
 * - Message-ID is ours to choose, and it is written onto the comment so that a
 *   reply quoting it lands on this ticket even when the tag was lost — which
 *   happens the moment somebody forwards the mail to a colleague who answers
 *   from a plain address.
 * - In-Reply-To and References point at the mail the ticket came from, which is
 *   what makes the answer appear under the original in the reader's own client
 *   rather than as an unrelated message about the same subject.
 *
 * Not a WorkspaceMailTemplate kind, unlike the two contract mails. There is no
 * text of ours to rewrite here: the body is what a colleague typed, and the
 * only line around it is the one saying which ticket it belongs to.
 */
class TicketReplyMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $messageId  The id this mail will carry, chosen by the caller because it has to be written onto the comment as well.
     * @param  string|null  $replyAddress  The tagged address answers should come back to, or null when the workspace has none.
     *
     * Not called $replyTo: Mailable already has a property of that name for the
     * addresses it is about to send to, and a promoted one beside it is a
     * redeclaration PHP refuses.
     */
    public function __construct(
        public TicketComment $comment,
        public string $messageId,
        public ?string $replyAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        $ticket = $this->comment->ticket;

        return new Envelope(
            /*
             * "Re:" and the number, because this lands in a mailbox rather than
             * on a board. The number is what a reader quotes back at us and
             * what ReceiveInboundEmail falls back to reading out of the
             * subject's own thread; the Re: is what keeps the client from
             * showing it as a new conversation.
             */
            subject: __('mail.ticket_reply.subject', [
                'number' => $ticket->number,
                'title' => $ticket->title,
            ]),
            replyTo: $this->replyAddress === null ? [] : [new Address($this->replyAddress)],
        );
    }

    public function headers(): Headers
    {
        $ticket = $this->comment->ticket;

        // The mail the ticket was made from, when there was one. A ticket
        // raised on the board and answered by mail has nothing to hang under,
        // and an empty References is better than one pointing at nothing.
        $original = $ticket->mail_message_id;

        return new Headers(
            messageId: $this->messageId,
            references: $original === null ? [] : [$original],
        );
    }

    public function content(): Content
    {
        $ticket = $this->comment->ticket;

        return new Content(
            markdown: 'mail.ticket-reply',
            with: [
                'ticket' => $ticket,
                'body' => $this->comment->body,
                'author' => $this->comment->author?->name,
                'workspaceName' => $ticket->workspace?->name,
            ],
        );
    }
}
