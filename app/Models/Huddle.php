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
 * @property int|null $recording_by
 * @property Carbon|null $recording_started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 */
#[Fillable(['channel_id', 'started_by', 'recording_by', 'recording_started_at', 'ended_at'])]
class Huddle extends Model
{
    /** @use HasFactory<HuddleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'ended_at' => 'datetime',
            'recording_started_at' => 'datetime',
        ];
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
     * Whose browser is recording it, while one is.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recording_by');
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

    public function isBeingRecorded(): bool
    {
        return $this->recording_by !== null;
    }

    /**
     * Take the recording notice down.
     *
     * Here rather than spelled out in three places, because it is called from
     * three: the browser saying it has stopped, the recorder leaving, and the
     * sweeper finding a huddle nobody is in. Silent when nothing was going on,
     * which is what lets every one of those call it without asking first.
     */
    public function stopRecording(): void
    {
        if (! $this->isBeingRecorded()) {
            return;
        }

        $this->forceFill(['recording_by' => null, 'recording_started_at' => null])->save();
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
