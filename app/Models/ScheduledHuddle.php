<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\ScheduledHuddleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A huddle in the diary.
 *
 * @property int $id
 * @property int $channel_id
 * @property int|null $created_by
 * @property string $title
 * @property Carbon $starts_at
 * @property int $duration_minutes
 * @property int|null $huddle_id
 * @property Carbon|null $announced_at
 * @property Carbon|null $cancelled_at
 */
#[Fillable(['channel_id', 'created_by', 'title', 'starts_at', 'duration_minutes'])]
class ScheduledHuddle extends Model
{
    /** @use HasFactory<ScheduledHuddleFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'announced_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The conversation this became, once it started.
     *
     * @return BelongsTo<Huddle, $this>
     */
    public function huddle(): BelongsTo
    {
        return $this->belongsTo(Huddle::class);
    }

    /**
     * Who was asked. Empty means the channel at large, which is a real answer
     * rather than an unfinished one — see the migration.
     *
     * @return BelongsToMany<User, $this>
     */
    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'scheduled_huddle_user');
    }

    /** When it is meant to be over. */
    public function endsAt(): CarbonInterface
    {
        return $this->starts_at->addMinutes($this->duration_minutes);
    }

    /**
     * Still to come, and not called off.
     *
     * "Upcoming" is asked of the start rather than of announced_at, so that an
     * appointment stays in the list for the minute between its moment arriving
     * and the dispatcher reaching it — a row that vanished in that gap would
     * look to the person waiting for it like it had been cancelled.
     */
    public function isUpcoming(): bool
    {
        return $this->cancelled_at === null && $this->starts_at->isFuture();
    }

    /**
     * The ones the dispatcher should announce: due, not announced, not called
     * off.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('announced_at')
            ->whereNull('cancelled_at')
            ->where('starts_at', '<=', now());
    }

    /**
     * What a channel still has coming, soonest first.
     *
     * Announced ones drop out rather than lingering: once the channel has been
     * told, the appointment is the conversation, and a diary entry beside a
     * live huddle is two things claiming to be the same meeting.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeUpcomingIn(Builder $query, Channel $channel): void
    {
        $query->where('channel_id', $channel->id)
            ->whereNull('announced_at')
            ->whereNull('cancelled_at')
            ->orderBy('starts_at');
    }
}
