<?php

namespace App\Models;

use Database\Factories\BoardCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A reply under a notice.
 *
 * Its own table rather than a self-reference on BoardPost, even though the two
 * carry nearly the same columns. A reply has no title, is never pinned, and
 * never appears on the board on its own — three things every query would have
 * to keep saying "except when it is a reply" about if they shared a table.
 *
 * @property int $id
 * @property string $board_post_id
 * @property int $user_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['board_post_id', 'user_id', 'body'])]
class BoardComment extends Model
{
    /** @use HasFactory<BoardCommentFactory> */
    use HasFactory, SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BoardPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BoardPost::class, 'board_post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
