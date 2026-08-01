<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WorkspaceInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->invitation->inviter->name
                .' nodigt je uit voor '
                .$this->invitation->workspace->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.workspace-invitation',
            with: [
                'invitation' => $this->invitation,
                'workspace' => $this->invitation->workspace,
                'inviter' => $this->invitation->inviter,
                'isGuest' => $this->invitation->role->isGuest(),
                'channels' => $this->invitation->channels()->orderBy('name')->get(),
                'url' => route('invitations.show', $this->invitation->token),
            ],
        );
    }
}
