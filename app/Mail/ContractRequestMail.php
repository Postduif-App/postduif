<?php

namespace App\Mail;

use App\Actions\Mail\RenderMailTemplate;
use App\Enums\MailTemplateKind;
use App\Models\ContractSigner;
use App\Support\Mail\RenderedMailTemplate;
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
 *
 * What it says is not necessarily ours. A workspace may have written its own
 * version of every line — see WorkspaceMailTemplate — and the two paths are one
 * path: the platform's text is itself a template, and this mailable asks
 * RenderMailTemplate for the answer without knowing which of the two it got.
 */
class ContractRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Worked out once and kept.
     *
     * envelope() and content() each need it, Laravel calls them separately, and
     * the work behind it is a query and a parse. Not a constructor argument,
     * because a mailable is built before the mailer knows which locale it will
     * be sent in and this reads translations — see SendContract::invite, which
     * sets the language around the send.
     */
    private ?RenderedMailTemplate $rendered = null;

    public function __construct(public ContractSigner $signer) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->rendered()->subject);
    }

    public function content(): Content
    {
        $rendered = $this->rendered();

        return new Content(
            markdown: 'mail.contract-request',
            with: [
                'heading' => $rendered->heading,
                'before' => $rendered->before,
                'after' => $rendered->after,
                'buttonLabel' => $rendered->buttonLabel,

                // This person's own link, never anybody else's: it is what
                // records who signed.
                'url' => $this->signer->signUrl(),
            ],
        );
    }

    /**
     * The four pieces of text this mail is made of.
     *
     * The values below are what the placeholders stand for, and a null among
     * them is not a gap to be papered over: it is what makes the sentence about
     * the deadline disappear on a contract that has none. See
     * RenderMailTemplate for the rule.
     */
    private function rendered(): RenderedMailTemplate
    {
        if ($this->rendered !== null) {
            return $this->rendered;
        }

        $contract = $this->signer->contract;
        $locale = $contract->mailLocale();

        return $this->rendered = app(RenderMailTemplate::class)->handle(
            MailTemplateKind::ContractRequest,
            $contract->workspace->mailTemplate(MailTemplateKind::ContractRequest, $locale),
            [
                'signer' => $this->signer->name,
                /*
                 * The workspace stands in for a departed author rather than the
                 * mail going out with a blank in it. A contract outlives
                 * whoever sent it — that is why created_by is nullOnDelete — so
                 * this is an ordinary case rather than an edge one.
                 */
                'sender' => $contract->author->name ?? $contract->workspace->name,
                'workspace' => $contract->workspace->name,
                // The title the author typed is theirs and stays as typed.
                'title' => $contract->title,
                'message' => $contract->message,
                'expires' => $contract->expires_at?->settings(['locale' => $locale])->translatedFormat(__('mail.format.date', [], $locale)),
            ],
            $locale,
        );
    }
}
