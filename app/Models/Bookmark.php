<?php

namespace App\Models;

use Database\Factories\BookmarkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A message one member set aside for themselves.
 *
 * The counterpart of a pin, and deliberately its opposite: a pin says "everyone
 * in this channel should read this first", while this says "I want to come back
 * to this" — and nobody else ever sees it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $message_id
 * @property int $channel_id
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'message_id', 'channel_id'])]
class Bookmark extends Model
{
    /** @use HasFactory<BookmarkFactory> */
    use HasFactory;

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
