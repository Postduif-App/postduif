<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * One announcement waiting to go out to several channels at once.
 *
 * Deliberately not N ScheduledMessages. The channels are fixed when it is
 * scheduled, but who may post where is not asked until it is sent — see
 * DispatchScheduledBroadcasts, which hands the whole set to the same
 * BroadcastToChannels that an immediate broadcast uses.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $created_by
 * @property string $body
 * @property Carbon $send_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 */
#[Fillable(['workspace_id', 'created_by', 'body', 'send_at'])]
class ScheduledBroadcast extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Where it is meant to land.
     *
     * The set as it was chosen, not as it will turn out: a channel that is
     * archived or closed to this member by then simply drops out at sending,
     * without the rest of the announcement failing with it.
     *
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'scheduled_broadcast_channel');
    }

    /**
     * Still to go out: not sent, and not given up on.
     *
     * Same reasoning as ScheduledMessage::scopePending — a failed one is not
     * pending, because retrying forever would have a broken announcement
     * knocking every minute until somebody noticed.
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
