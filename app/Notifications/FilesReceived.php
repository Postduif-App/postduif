<?php

namespace App\Notifications;

use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Models\UploadLinkSubmission;
use App\Models\User;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\SendsPushover;
use App\Notifications\Contracts\SendsWebPush;
use App\Notifications\Messages\PushoverMessage;
use App\Notifications\Messages\WebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Er is iets binnengekomen via je uploadlink."
 *
 * The link in every channel goes to the overview behind a login, never to the
 * files themselves. What a stranger sent is on the private disk behind a
 * policy, and a mail or a push payload is the one place it must not end up —
 * both get forwarded, archived and, for push, stored by a service outside the
 * EU.
 */
class FilesReceived extends Notification implements SendsPushover, SendsWebPush, ShouldQueue
{
    use Queueable;

    public function __construct(public readonly UploadLinkSubmission $submission)
    {
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            $notifiable->notify_via_mail ? 'mail' : null,
            $notifiable->wantsPushover() ? PushoverChannel::class : null,
            $notifiable->wantsWebPush() ? WebPushChannel::class : null,
        ]));
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->mailer(app(ResolveWorkspaceMailer::class)->handle($this->submission->uploadLink->workspace))
            ->subject($this->subject())
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line($this->sentence());

        if ($this->submission->note !== null) {
            $mail->line('“'.$this->submission->note.'”');
        }

        return $mail->action(__('notifications.upload_link.open'), $this->url());
    }

    public function toPushover(User $notifiable): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->subject(),
            body: $this->sentence(),
            url: $this->url(),
        );
    }

    /**
     * Tagged per link, so three colleagues at one customer sending their part
     * over an afternoon is one bubble that keeps up rather than three.
     */
    public function toWebPush(User $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: $this->subject(),
            body: $this->sentence(),
            url: $this->url(),
            tag: 'upload-link-'.$this->submission->upload_link_id,
            renotify: true,
        );
    }

    private function subject(): string
    {
        return __('notifications.upload_link.subject', [
            'title' => $this->submission->uploadLink->title,
        ]);
    }

    private function sentence(): string
    {
        return trans_choice('notifications.upload_link.body', $this->submission->files()->count(), [
            'name' => $this->submission->name,
            'title' => $this->submission->uploadLink->title,
        ]);
    }

    private function url(): string
    {
        return route('chat.transfers.index', $this->submission->uploadLink->workspace);
    }
}
