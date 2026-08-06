<?php

namespace App\Models;

use Database\Factories\HuddleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * People talking in a channel, right now or an hour ago.
 *
 * @property int $id
 * @property int $channel_id
 * @property int|null $started_by
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 */
#[Fillable(['channel_id', 'started_by', 'ended_at'])]
class Huddle extends Model
{
    /** @use HasFactory<HuddleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ended_at' => 'datetime'];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Everybody who was ever in it, including the ones who have since left.
     *
     * @return HasMany<HuddleParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(HuddleParticipant::class);
    }

    /**
     * Who is in it now.
     *
     * @return HasMany<HuddleParticipant, $this>
     */
    public function present(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    public function isLive(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * The ones still going.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('ended_at');
    }
}
