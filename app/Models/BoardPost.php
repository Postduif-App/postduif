<?php

namespace App\Models;

use Database\Factories\BoardPostFactory;
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
 * A notice on the workspace's prikbord.
 *
 * The thing it is not is a message: a message is read once, in the order it
 * arrived, and is then behind you. A notice stays up, is read by people who
 * were not there when it went up, and stops mattering on its own schedule
 * rather than by being scrolled past. That is why it carries a title — a
 * message needs none, because the conversation around it says what it is about,
 * and a notice has no conversation around it.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $title
 * @property string $body
 * @property Carbon|null $pinned_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['workspace_id', 'user_id', 'title', 'body'])]
class BoardPost extends Model
{
    /** @use HasFactory<BoardPostFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whoever put it up, or null once they have left.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The replies, oldest first — a thread under a notice reads forwards, the
     * way a conversation does, not backwards the way the board itself does.
     *
     * @return HasMany<BoardComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(BoardComment::class)->oldest('id');
    }

    /**
     * The emoji under it, from everybody who left one.
     *
     * Ungrouped on purpose: who reacted with what is a different question per
     * reader ("is one of these mine?"), and a relation that already counted
     * them would have thrown away the only column that answers it.
     *
     * @return HasMany<BoardPostReaction, $this>
     */
    public function reactions(): HasMany
    {
        return $this->hasMany(BoardPostReaction::class);
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /**
     * Board order: pinned notices first, and the newest of what is left under
     * them.
     *
     * Ordering on pinned_at descending puts the nulls last on MySQL and first
     * on Postgres, which is exactly the sort of difference that shows up only
     * in production. The boolean expression in front settles it in both:
     * pinned or not is decided first, and pinned_at only breaks ties among the
     * ones that are.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInBoardOrder(Builder $query): void
    {
        $query
            ->orderByRaw('CASE WHEN pinned_at IS NULL THEN 1 ELSE 0 END')
            ->latest('pinned_at')
            ->latest('created_at');
    }
}
