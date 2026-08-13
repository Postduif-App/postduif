<?php

namespace App\Actions\Mail;

use App\Mail\TicketReplyMail;
use App\Models\TicketComment;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Post a colleague's answer back to whoever wrote in.
 *
 * The return leg of ReceiveInboundEmail, and the reason it is an action of its
 * own rather than a few lines in CommentOnTicket: what decides whether a
 * comment leaves the building is a question about the ticket and the workspace
 * — did this arrive by mail, is there an address to answer to, can this
 * workspace send at all — and none of that belongs in the action that writes a
 * row.
 *
 * Everything here is a refusal except the last four lines. That is the shape
 * this wants: a mail that should not go out must not go out for one reason at a
 * time, and each of them says which.
 */
class ReplyToTicketSender
{
    public function __construct(private readonly ResolveWorkspaceMailer $resolveMailer) {}

    /**
     * Send this comment on to the sender of the ticket, if it is one to send.
     *
     * Returns the message id it went out under, or null when nothing was sent —
     * which the caller has no use for and a test has every use for.
     */
    public function handle(TicketComment $comment): ?string
    {
        /*
         * Only what a colleague wrote. A comment with no member behind it came
         * in through the letterbox — see ReceiveInboundEmail — and mailing that
         * back to the person who sent it is a loop with their own words in it.
         */
        if ($comment->user_id === null) {
            return null;
        }

        $ticket = $comment->ticket;

        if ($ticket === null || ! $ticket->arrivedByEmail()) {
            return null;
        }

        $settings = $ticket->workspace?->loadMissing('mailSettings')->mailSettings;

        /*
         * A workspace that no longer takes mail does not answer it either. The
         * ticket is still readable and answerable on the board; what stops is
         * the copy going out to an address whose replies would now land
         * nowhere.
         */
        if ($settings === null || ! $settings->receivesMail()) {
            return null;
        }

        /*
         * Chosen here rather than left to the transport, because it has to be
         * written onto the comment as well: an inbound reply quoting it is how
         * this ticket is found again when the tagged address was lost. The
         * domain is the workspace's own, so the id looks like it came from
         * where the mail came from — some providers refuse one that does not.
         */
        $messageId = $this->messageId($settings->inbound_address);

        Mail::mailer($this->resolveMailer->handle($ticket->workspace))
            ->to($ticket->sender_email)
            ->send((new TicketReplyMail(
                $comment,
                $messageId,
                $settings->replyAddressFor($ticket->number),
            ))->locale($comment->author?->preferredLocale() ?? (string) config('app.locale')));

        $comment->forceFill(['mail_message_id' => $messageId])->save();

        return $messageId;
    }

    /**
     * An id no other mail will have, under the workspace's own domain.
     *
     * Without the angle brackets: Symfony writes those, and an id that arrives
     * here already wearing them ends up with two pairs and matches nothing on
     * the way back.
     */
    private function messageId(?string $inboundAddress): string
    {
        $domain = str_contains((string) $inboundAddress, '@')
            ? Str::after((string) $inboundAddress, '@')
            : (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'postduif.app');

        return sprintf('%s@%s', Str::uuid()->toString(), $domain);
    }
}
