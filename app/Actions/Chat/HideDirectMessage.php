<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\User;

/**
 * Putting a one-on-one conversation away, and getting it back.
 *
 * "Verwijderen" in the interface, but nothing is deleted: a DM belongs to two
 * people, and letting one of them erase it would take the other's copy of a
 * conversation they were also part of. So this is a per-member flag on the
 * pivot — the row leaves your sidebar, the messages stay where they are, and
 * the person on the other side notices nothing at all.
 */
class HideDirectMessage
{
    /**
     * The newest message is written down along with the moment: it is the mark
     * everything said afterwards is measured against, and a timestamp of whole
     * seconds cannot tell a reply from the same second apart from the click
     * that hid the conversation. Null for a conversation nobody has said
     * anything in yet — then the first word brings it back.
     */
    public function hide(Channel $channel, User $user): void
    {
        $channel->members()->updateExistingPivot($user->id, [
            'hidden_at' => now(),
            'hidden_message_id' => $channel->messages()->max('id'),
        ]);
    }

    /**
     * Put it back, if it was away.
     *
     * Guarded rather than unconditional: this runs on every visit to a DM, and
     * an unguarded write would touch a row on every page load for a
     * conversation nobody ever hid.
     */
    public function reopen(Channel $channel, User $user): void
    {
        $hiddenAt = $channel->members()->whereKey($user->id)->value('channel_user.hidden_at');

        if ($hiddenAt !== null) {
            $channel->members()->updateExistingPivot($user->id, [
                'hidden_at' => null,
                'hidden_message_id' => null,
            ]);
        }
    }
}
