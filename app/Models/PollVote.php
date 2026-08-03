<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody's vote on one option.
 *
 * Deliberately not hidden and deliberately not anonymous: who voted for what is
 * part of what a poll in a work channel is for. The obligation that comes with
 * that is on the interface, which says so before anybody clicks.
 *
 * @property int $id
 * @property int $poll_option_id
 * @property int $user_id
 * @property Carbon|null $created_at
 */
#[Fillable(['poll_option_id', 'user_id'])]
class PollVote extends Model
{
    /** A vote happens once and is never edited; changing it is a new row. */
    public const UPDATED_AT = null;

    /** @return BelongsTo<PollOption, $this> */
    public function option(): BelongsTo
    {
        return $this->belongsTo(PollOption::class, 'poll_option_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
