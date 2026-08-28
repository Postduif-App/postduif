<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\ChannelMembership;
use App\Models\User;
use App\Notifications\NewChannelMessage;
use Illuminate\Support\Collection;

/**
 * Push, the moment it happens, to the members who asked not to wait for the
 * away summary.
 *
 * A separate action from FindMissedActivity on purpose: that one runs on a
 * schedule and reports what a member missed since they last read a channel,
 * for members who are fine hearing about it later. This one runs inline with
 * SendMessage and reports one message, right away, to whoever chose "meteen"
 * over "straks" — for this channel specifically, or for every channel by
 * default.
 */
class NotifyInstantSubscribers
{
    public function __construct(private readonly ChannelPresence $presence) {}

    /**
     * @param  Collection<int, User>  $recipients  Every member the message
     *                                             reaches, with their pivot
     *                                             loaded — see Channel::members(). The sender is
     *                                             already excluded by the caller.
     * @param  Collection<int, int>  $mentioned  User ids named in the message.
     */
    public function handle(Channel $channel, string $authorName, Collection $recipients, Collection $mentioned): void
    {
        $subscribers = $recipients->filter(fn (User $user): bool => $this->wants($user));

        if ($subscribers->isEmpty()) {
            return;
        }

        // One presence check for the whole message rather than one per
        // candidate: by here the list is already the handful of members who
        // asked for this, not the whole channel.
        $watching = $this->presence->handle($channel);

        foreach ($subscribers as $user) {
            // Already looking at it. Sending a push on top would tell them
            // twice about the same message — once on screen, once in their
            // pocket — for the one moment instant notifications exist to
            // avoid: waiting to be told something they already saw happen.
            if ($watching->contains($user->id)) {
                continue;
            }

            $user->notify(new NewChannelMessage(
                $channel->workspace,
                $channel,
                $authorName,
                $mentioned->contains($user->id),
            ));
        }
    }

    /**
     * Whether this member wants to hear about this channel right now.
     *
     * Availability first: "niet storen" is the member saying so out loud in
     * the moment, and it outranks a channel preference set weeks ago — the
     * same rule User::wantsAbsenceNotifications() follows. A mute is the
     * member's more specific and more recent word on this one channel, so it
     * wins over "instant" too; asking for a channel to push instantly and
     * then muting it is muting it.
     */
    private function wants(User $user): bool
    {
        if (! $user->wantsInstantPush()) {
            return false;
        }

        $membership = $user->getAttribute('pivot');

        if (! $membership instanceof ChannelMembership || $membership->isMuted()) {
            return false;
        }

        return $membership->wantsInstantNotifications($user);
    }
}
