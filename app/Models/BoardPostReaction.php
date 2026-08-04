<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One emoji, from one person, under one notice.
 *
 * @property int $id
 * @property string $board_post_id
 * @property int $user_id
 * @property string $emoji
 */
#[Fillable(['board_post_id', 'user_id', 'emoji'])]
class BoardPostReaction extends Model
{
    /** @return BelongsTo<BoardPost, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(BoardPost::class, 'board_post_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
