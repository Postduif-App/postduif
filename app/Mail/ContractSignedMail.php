<?php

namespace App\Mail;

use App\Actions\Mail\RenderMailTemplate;
use App\Enums\MailTemplateKind;
use App\Models\ContractSigner;
use App\Support\Mail\RenderedMailTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The finished document, to somebody who signed it.
 *
 * The counterpart of ContractRequestMail: that one asks, this one closes the
 * matter. Addressed to a signer rather than to a contract for the same reason,
 * and here for a second one — the link in it opens their own copy, and a shared
 * one would hand a stranger the record of who signed what.
 *
 * The PDF goes along as an attachment rather than only as a link, and that is
 * the point of the whole mail. Somebody who has just signed something has a
 * file they are entitled to keep, and "hij staat voor je klaar achter deze
 * link" is a copy that lives exactly as long as this application does. The link
 * is there too, for the mail server that strips attachments and for the person
 * who comes looking a year from now.
 */
class ContractSignedMail extends Mailable
{
    use Queueable, SerializesModels;

    /** Worked out once and kept — see ContractRequestMail for why. */
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
            markdown: 'mail.contract-signed',
            with: [
                'heading' => $rendered->heading,
                'before' => $rendered->before,
                'after' => $rendered->after,
                'buttonLabel' => $rendered->buttonLabel,

                // Their own, never anybody else's — the same rule the signing
                // link follows.
                'url' => $this->signer->signedCopyUrl(),
            ],
        );
    }

    /**
     * What this mail says, from the workspace's own text where there is one.
     *
     * No deadline among the values, unlike the request: a document that has
     * been signed has no expiry left to mention, and MailTemplateKind does not
     * offer the placeholder for it.
     */
    private function rendered(): RenderedMailTemplate
    {
        if ($this->rendered !== null) {
            return $this->rendered;
        }

        $contract = $this->signer->contract;
        $locale = $contract->mailLocale();

        return $this->rendered = app(RenderMailTemplate::class)->handle(
            MailTemplateKind::ContractSigned,
            $contract->workspace->mailTemplate(MailTemplateKind::ContractSigned, $locale),
            [
                'signer' => $this->signer->name,
                /*
                 * The workspace stands in for a departed author, as in the
                 * request mail: a contract outlives whoever sent it —
                 * created_by is nullOnDelete — and a blank would show up most
                 * often on the oldest contracts.
                 */
                'sender' => $contract->author?->name ?? $contract->workspace->name,
                'workspace' => $contract->workspace->name,
                'title' => $contract->title,
                'signed_at' => $this->signer->signed_at?->locale($locale)->translatedFormat(__('mail.format.date_time', [], $locale)),
            ],
            $locale,
        );
    }

    /**
     * The document itself.
     *
     * Attached from its path rather than from the media library's stream, so the
     * bytes never pass through PHP's memory: a twenty-page contract with a
     * subsetted font in it is megabytes, and this mail may go out to five people
     * in one loop.
     *
     * An empty list when there is nothing to attach — which SendSignedContract
     * already refuses to let happen, and which is worth surviving anyway rather
     * than throwing on a mail that has a working link in it.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $media = $this->signer->contract->signedCopy();

        if ($media === null || ! is_file($media->getPath())) {
            return [];
        }

        return [
            Attachment::fromPath($media->getPath())
                ->as($media->file_name)
                ->withMime('application/pdf'),
        ];
    }
}
