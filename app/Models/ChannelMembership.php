<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Somebody's place in a channel, and everything the app remembers about how
 * they read it.
 *
 * Five of these columns are bookkeeping rather than membership: where they had
 * read to, what they were last told about, whether they muted it, and — for a
 * DM — whether they put it away. Named here so that reading one is not a guess.
 *
 * @property string|null $last_read_message_id
 * @property Carbon|null $last_read_at
 * @property string|null $last_notified_message_id
 * @property Carbon|null $muted_at
 * @property Carbon|null $muted_until
 * @property Carbon|null $favorited_at
 * @property Carbon|null $joined_at
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_message_id
 */
class ChannelMembership extends Pivot
{
    protected $table = 'channel_user';

    /**
     * Whether this channel is quiet for this member right now.
     *
     * A mute with no end date lasts until it is switched off; one with a date
     * simply stops mattering when the date passes, without anything having to
     * go and clear the column. The query-side twin is Channel::scopeMuted().
     */
    public function isMuted(): bool
    {
        if ($this->muted_at === null) {
            return false;
        }

        return $this->muted_until === null || $this->muted_until->isFuture();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'muted_at' => 'datetime',
            'muted_until' => 'datetime',
            'favorited_at' => 'datetime',
            'joined_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }
}
