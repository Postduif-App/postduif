<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int $user_id
 * @property string|null $parent_id
 * @property string $body
 * @property int $reply_count
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 */
#[Fillable(['id', 'workspace_id', 'channel_id', 'user_id', 'parent_id', 'body'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function isThreadParent(): bool
    {
        return $this->parent_id === null && $this->reply_count > 0;
    }

    /**
     * Full-text search against the generated tsvector column.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, string $terms): void
    {
        $query->whereRaw(
            "search_vector @@ plainto_tsquery('simple', ?)",
            [$terms]
        );
    }

    /**
     * ULIDs sort lexicographically by creation time, so paging on the primary
     * key gives stable cursors even while new messages arrive.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBefore(Builder $query, ?string $cursor): void
    {
        $query->when($cursor, fn (Builder $query) => $query->where('id', '<', $cursor));
    }
}
