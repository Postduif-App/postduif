<?php

namespace App\Notifications;

use App\Actions\Chat\FindMissedActivity;
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
    ) {}

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
            ->subject($this->subject())
            ->greeting('Hoi '.$notifiable->name.',')
            ->line('Er is gepraat in '.$this->workspace->name.' terwijl je er niet was.');

        foreach ($this->channels as $channel) {
            $mail->line('• '.$channel['label'].' — '.$this->countsFor($channel));
        }

        return $mail
            ->action('Openen', route('chat.index', $this->workspace))
            // Where to go when this is not what you wanted. A notification that
            // does not say how to stop is one people mark as spam.
            ->line('Instellen hoe vaak je dit krijgt kan bij [Notificaties]('.route('notifications.edit').').');
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
            urlTitle: 'Openen in Pcom',
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

        if ($mentions > 0) {
            return $mentions === 1
                ? 'Iemand noemde je in '.$this->workspace->name
                : $mentions.' keer genoemd in '.$this->workspace->name;
        }

        $unread = $this->channels->sum('unread');

        return $unread === 1
            ? 'Eén nieuw bericht in '.$this->workspace->name
            : $unread.' nieuwe berichten in '.$this->workspace->name;
    }

    /**
     * @param  Summary  $channel
     */
    private function countsFor(array $channel): string
    {
        $counts = [$channel['unread'].' '.($channel['unread'] === 1 ? 'bericht' : 'berichten')];

        if ($channel['mentions'] > 0) {
            $counts[] = $channel['mentions'].'x genoemd';
        }

        return implode(', ', $counts);
    }
}
