<?php

namespace App\Mail;

use App\Models\ContractSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The request to sign, to the one address it was made for.
 *
 * Addressed to a signer rather than to a contract, for the reason
 * TransferReadyMail is: the link differs per person, and that is the entire
 * point — a shared link could tell you that somebody signed but not who.
 *
 * The same mailable is used for the reminder. Whoever needs reminding has
 * almost certainly lost the first mail, so what they want is the thing they
 * lost rather than a note about it.
 */
class ContractRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public ContractSigner $signer) {}

    public function envelope(): Envelope
    {
        $contract = $this->signer->contract;

        return new Envelope(
            /*
             * The workspace stands in for a departed author, rather than the
             * subject going out with a blank in it. A contract outlives whoever
             * sent it — that is why created_by is nullOnDelete — so this is an
             * ordinary case rather than an edge one.
             */
            subject: __('notifications.contract.subject', [
                'sender' => $contract->author->name ?? $contract->workspace->name,
                // The title the author typed is theirs and stays as typed.
                'what' => $contract->title,
            ]),
        );
    }

    public function content(): Content
    {
        $contract = $this->signer->contract;

        return new Content(
            markdown: 'mail.contract-request',
            with: [
                'contract' => $contract,
                'signerName' => $this->signer->name,
                'senderName' => $contract->author?->name,
                'workspaceName' => $contract->workspace->name,

                // This person's own link, never anybody else's: it is what
                // records who signed.
                'url' => $this->signer->signUrl(),
            ],
        );
    }
}
