<?php

namespace App\Mail;

use App\Models\TransferRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The link, to the one address it was made for.
 *
 * Addressed to a recipient rather than to a transfer, because the link differs
 * per address — that is the entire reason this audience exists, and a mailable
 * that took the transfer would have to be told which token to write.
 */
class TransferReadyMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public TransferRecipient $recipient) {}

    public function envelope(): Envelope
    {
        $transfer = $this->recipient->transfer;

        return new Envelope(
            // ?? swallows the null on a departed sender, so ?-> would be
            // saying the same thing twice.
            subject: ($transfer->sender->name ?? $transfer->workspace->name)
                .' stuurt je '
                .($transfer->title ?? 'bestanden'),
        );
    }

    public function content(): Content
    {
        $transfer = $this->recipient->transfer;

        return new Content(
            markdown: 'mail.transfer-ready',
            with: [
                'transfer' => $transfer,
                'senderName' => $transfer->sender?->name,
                'workspaceName' => $transfer->workspace->name,
                'files' => $transfer->files(),
                // The recipient's own token, never the transfer's: the shared
                // one opens nothing for this audience.
                'url' => route('transfers.show', $this->recipient->token),
            ],
        );
    }
}
