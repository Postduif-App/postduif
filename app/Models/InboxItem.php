<?php

namespace App\Models;

use App\Enums\InboxItemType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One reason a member has to look at something.
 *
 * Written when the reason arises rather than worked out when the inbox is
 * opened, because a derived row has nowhere to keep read_at — and a list you
 * cannot mark off is not an inbox.
 *
 * @property int $id
 * @property string|null $message_id
 * @property string|null $poll_id
 * @property int $user_id
 * @property int|null $actor_id
 * @property int $channel_id
 * @property InboxItemType $type
 * @property Carbon|null $read_at
 */
/*
 * read_at is fillable because bumping a row is how the fan-out works: an
 * updateOrCreate that could not write it would leave a collapsed row sitting at
 * "read" while new replies piled up behind it, and the inbox would go quiet
 * exactly when it had something to say.
 */
#[Fillable(['message_id', 'poll_id', 'user_id', 'actor_id', 'channel_id', 'type', 'read_at'])]
class InboxItem extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'type' => InboxItemType::class,
        ];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Poll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(Poll::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who caused it. Null for a webhook, which has no member behind it, and
     * for somebody who has since left.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @param  Builder<$this>  $query */
    public function scopeUnread(Builder $query): void
    {
        $query->whereNull('read_at');
    }

    /**
     * Rows of one kind.
     *
     * The badge in the sidebar counts mentions and nothing else: a thread
     * carrying on is worth a line in the inbox, but not a number beside a
     * channel name that reads as "somebody asked you something".
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOfType(Builder $query, InboxItemType $type): void
    {
        $query->where('type', $type);
    }
}
