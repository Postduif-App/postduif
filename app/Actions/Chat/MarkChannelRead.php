<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\Mention;
use App\Models\Message;
use App\Models\User;

class MarkChannelRead
{
    /**
     * Move the member's read pointer to the given message.
     *
     * A pointer rather than a counter: a counter has to be adjusted on every
     * post, edit and delete and drifts out of step the first time one of those
     * fails halfway. A pointer only ever moves forward, and the unread total is
     * derived from it — so it cannot disagree with the messages themselves.
     */
    public function handle(Channel $channel, User $user, ?string $messageId = null): void
    {
        // The pointer itself, not the membership row it sits on: the member's
        // name and status have no bearing on where they had read to.
        $membership = $channel->members()->whereKey($user->id);

        if (! $membership->exists()) {
            return;
        }

        $readUpTo = $membership->value('channel_user.last_read_message_id');

        $messageId ??= $channel->messages()->max('id');

        if ($messageId === null) {
            return;
        }

        // ULIDs sort by creation time, so this also guards against an older
        // pointer arriving late and dragging the member back into the past.
        // The timestamp is written either way: opening a channel that holds
        // nothing new is still the member being present in it, and that is what
        // the absence notifications ask about.
        if ($readUpTo !== null && $readUpTo >= $messageId) {
            $channel->members()->updateExistingPivot($user->id, ['last_read_at' => now()]);

            return;
        }

        $channel->members()->updateExistingPivot($user->id, [
            'last_read_message_id' => $messageId,
            'last_read_at' => now(),
        ]);

        Mention::query()
            ->where('user_id', $user->id)
            ->where('channel_id', $channel->id)
            ->unread()
            ->whereIn('message_id', Message::query()
                ->select('id')
                ->where('channel_id', $channel->id)
                ->where('id', '<=', $messageId))
            ->update(['read_at' => now()]);
    }
}
