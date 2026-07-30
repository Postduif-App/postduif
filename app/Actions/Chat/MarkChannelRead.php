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
        $membership = $channel->members()->find($user->id);

        if ($membership === null) {
            return;
        }

        $messageId ??= $channel->messages()->max('id');

        if ($messageId === null) {
            return;
        }

        // ULIDs sort by creation time, so this also guards against an older
        // pointer arriving late and dragging the member back into the past.
        if ($membership->pivot->last_read_message_id !== null
            && $membership->pivot->last_read_message_id >= $messageId) {
            return;
        }

        $channel->members()->updateExistingPivot($user->id, [
            'last_read_message_id' => $messageId,
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
