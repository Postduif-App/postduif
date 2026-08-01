<?php

namespace App\Models;

use Database\Factories\ScheduledMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $channel_id
 * @property int $user_id
 * @property string $body
 * @property Carbon $send_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 */
#[Fillable(['channel_id', 'user_id', 'body', 'send_at'])]
class ScheduledMessage extends Model
{
    /** @use HasFactory<ScheduledMessageFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
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

    /**
     * Still to go out: not sent, and not given up on.
     *
     * A failed one is deliberately not pending. Retrying it forever would mean
     * a channel that lost its posting rights re-attempting every minute until
     * somebody notices; the author is told instead, and can send it again.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('sent_at')->whereNull('failed_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->pending()->where('send_at', '<=', now());
    }

    public function isPending(): bool
    {
        return $this->sent_at === null && $this->failed_at === null;
    }
}
