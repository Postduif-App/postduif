<?php

namespace App\Actions\Chat;

use App\Models\Channel;
use App\Models\EphemeralNotice;
use App\Models\User;

/**
 * Say something in a channel to one person only.
 *
 * The answer to something they did, said where they did it: a command they
 * typed, a button they pressed. Nobody else sees it, nothing is broadcast, and
 * it never becomes part of what the channel said — see the migration for why
 * that is a separate table rather than a flag on a message.
 */
class NoticeInChannel
{
    /**
     * How long a receipt for something that went to plan is worth showing.
     *
     * Ten minutes rather than until it is dismissed: "de workflow is gestart"
     * is worth reading once, and a channel that slowly fills with your own old
     * receipts is a channel you scroll past your own history in. A notice about
     * something that failed passes null and stays, because being read is the
     * whole of its job.
     */
    public const MINUTES = 10;

    public function handle(
        Channel $channel,
        User $reader,
        string $body,
        ?string $authorName = null,
        bool $keep = false,
    ): EphemeralNotice {
        return EphemeralNotice::create([
            'channel_id' => $channel->id,
            'user_id' => $reader->id,
            'body' => $body,
            'author_name' => $authorName,
            'expires_at' => $keep ? null : now()->addMinutes(self::MINUTES),
        ]);
    }
}
