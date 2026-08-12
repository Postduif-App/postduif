<?php

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Dit kwam aan, en zo ziet het eruit."
 *
 * Not queued, unlike everything else that goes out of here. The entire point of
 * pressing the button is to find out whether these credentials work, and an
 * answer that arrives in a worker log a minute later is not an answer — the
 * send has to happen inside the request so the failure can be shown to the
 * person who caused it.
 *
 * It names the sender it went out as, which is the half of the configuration
 * that is otherwise invisible: a message that arrives from the wrong address is
 * a working transport and a broken setup, and only the mail itself can show
 * that.
 */
class MailSettingsTestMail extends Mailable
{
    use SerializesModels;

    public function __construct(public Workspace $workspace) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.test.subject', ['workspace' => $this->workspace->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.settings-test',
            with: ['workspace' => $this->workspace],
        );
    }
}
