<?php

namespace App\Notifications;

use App\Actions\Chat\FindMissedActivity;
use App\Actions\Mail\ResolveWorkspaceMailer;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Contracts\SendsPushover;
use App\Notifications\Messages\PushoverMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * "You have been away, and this happened."
 *
 * One notification for everything the member missed rather than one per
 * message: the whole point is that they are not looking, and a phone that buzzes
 * per message during a busy afternoon is a phone people switch off.
 *
 * Carries the whole shape FindMissedActivity produces, newestId included: the
 * mail does not use it, but a Collection is invariant in its value type, so
 * promising less here would make the real thing unassignable.
 *
 * @phpstan-import-type MissedChannel from FindMissedActivity as Summary
 */
class ChannelActivity extends Notification implements SendsPushover, ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, Summary>  $channels  What was missed, busiest first.
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly Collection $channels,
    ) {
        // Ahead of the slow work: somebody is waiting for this one.
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
        ]));
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            /*
             * On the workspace's own mailer when it has one. Resolved here
             * rather than passed in, because this runs on a queue worker: the
             * config the action writes does not survive the job that wrote it,
             * so it has to be asked for again where the message is built. Null
             * lands on the application's own, which is what MailChannel reads
             * an unset mailer as.
             */
            ->mailer(app(ResolveWorkspaceMailer::class)->handle($this->workspace))
            ->subject($this->subject())
            ->greeting(__('notifications.greeting', ['name' => $notifiable->name]))
            ->line(__('notifications.activity.intro', ['workspace' => $this->workspace->name]));

        foreach ($this->channels as $channel) {
            $mail->line('• '.$channel['label'].' — '.$this->countsFor($channel));
        }

        return $mail
            ->action(__('notifications.activity.open'), route('chat.index', $this->workspace))
            // Where to go when this is not what you wanted. A notification that
            // does not say how to stop is one people mark as spam.
            ->line(__('notifications.activity.preferences', ['url' => route('notifications.edit')]));
    }

    public function toPushover(User $notifiable): PushoverMessage
    {
        $lines = $this->channels
            ->map(fn (array $channel): string => $channel['label'].' — '.$this->countsFor($channel))
            ->join("\n");

        return new PushoverMessage(
            title: $this->subject(),
            body: $lines,
            url: route('chat.index', $this->workspace),
            urlTitle: __('notifications.activity.open_in_app'),
        );
    }

    /**
     * Mentions lead when there are any: being addressed by name is a different
     * thing from a channel having been busy, and it is the half somebody
     * actually has to act on.
     */
    private function subject(): string
    {
        $mentions = $this->channels->sum('mentions');

        $workspace = ['workspace' => $this->workspace->name];

        if ($mentions > 0) {
            return trans_choice('notifications.activity.subject_mentions', $mentions, $workspace);
        }

        return trans_choice(
            'notifications.activity.subject_unread',
            $this->channels->sum('unread'),
            $workspace,
        );
    }

    /**
     * @param  Summary  $channel
     */
    private function countsFor(array $channel): string
    {
        $counts = [trans_choice('notifications.activity.messages', $channel['unread'])];

        if ($channel['mentions'] > 0) {
            $counts[] = __('notifications.activity.mentions', ['count' => $channel['mentions']]);
        }

        return implode(', ', $counts);
    }
}
