<?php

namespace App\Notifications;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Channels\PushoverChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\Contracts\SendsPushover;
use App\Notifications\Contracts\SendsWebPush;
use App\Notifications\Messages\PushoverMessage;
use App\Notifications\Messages\WebPushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * "Something just happened, right now."
 *
 * The instant counterpart to ChannelActivity: that one waits for the away
 * threshold and bundles a channel's worth of activity into one summary, for
 * members who are fine hearing about it later. This one fires once per
 * message, for the members who asked this particular channel — or every
 * channel, by default — to reach them immediately instead. See
 * NotifyInstantSubscribers for who that ends up being.
 *
 * Push only, deliberately. A notification per message is exactly the buzz
 * mail is bad at: nobody wants an inbox that fills up one line at a time, and
 * mail already has the away summary for that. Only the two channels meant for
 * a single device get to be instant.
 */
class NewChannelMessage extends Notification implements SendsPushover, SendsWebPush, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly Channel $channel,
        public readonly string $authorName,
        public readonly bool $mentioned,
    ) {
        // Ahead of the slow work: somebody is waiting for this one, more so
        // than for the summary — the whole point of "instant" is that it does
        // not sit behind whatever else the queue is doing.
        $this->onQueue('notifications');
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            $notifiable->wantsPushover() ? PushoverChannel::class : null,
            $notifiable->wantsWebPush() ? WebPushChannel::class : null,
        ]));
    }

    public function toPushover(User $notifiable): PushoverMessage
    {
        return new PushoverMessage(
            title: $this->subject($notifiable),
            body: __('notifications.instant.body', ['author' => $this->authorName]),
            url: route('chat.show', [$this->workspace, $this->channel]),
            urlTitle: __('notifications.activity.open_in_app'),
        );
    }

    /**
     * One bubble per message, replacing the last one under the same tag
     * rather than piling up — a member who asked for instant notifications on
     * a busy channel still only needs the newest one on screen. Buzzing again
     * each time is exactly the point, unlike ChannelActivity's quiet updates:
     * "instant" only means something if it interrupts.
     */
    public function toWebPush(User $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: $this->subject($notifiable),
            /*
             * The author's name and nothing they wrote — the same boundary
             * ChannelActivity draws, for the same reason: this payload is
             * decrypted by a push service outside the EU, and the message
             * itself stays behind the click, on our own domain, behind a
             * login.
             */
            body: __('notifications.instant.body', ['author' => $this->authorName]),
            url: route('chat.show', [$this->workspace, $this->channel]),
            tag: 'channel-instant-'.$this->channel->id,
            renotify: true,
        );
    }

    private function subject(User $notifiable): string
    {
        $channel = $this->label($notifiable);

        return $this->mentioned
            ? __('notifications.instant.subject_mention', ['channel' => $channel])
            : __('notifications.instant.subject_message', ['channel' => $channel]);
    }

    /**
     * A DM has no name of its own — see FindMissedActivity::labelFor(), which
     * this mirrors.
     */
    private function label(User $notifiable): string
    {
        return $this->channel->isDirect()
            ? $this->channel->displayNameFor($notifiable)
            : '#'.$this->channel->name;
    }
}
