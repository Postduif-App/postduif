<?php

namespace App\Models;

use Database\Factories\EphemeralNoticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line in a channel that only one person sees.
 *
 * "Ephemeral" in the sense Slack uses it: the answer to something you did — a
 * command you typed, a button you pressed — said where you did it, and said to
 * nobody else. It is deliberately not a message. See the migration for why the
 * two are kept apart.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property string $body
 * @property string|null $author_name
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
#[Fillable(['channel_id', 'user_id', 'body', 'author_name', 'expires_at'])]
class EphemeralNotice extends Model
{
    /** @use HasFactory<EphemeralNoticeFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The ones still worth drawing.
     *
     * A notice with no end stays until it is dismissed: that is what a receipt
     * for something that went wrong should do, because being read is the whole
     * of its job.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
