<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of time somebody was at work.
 *
 * Open while it is still running — ended_at is null and nothing else says so.
 * Everything the overview shows is derived from the two ends: how long a
 * stretch lasted, which day it counts towards, whether it is still going.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $corrected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'started_at', 'ended_at'])]
class TimeEntry extends Model
{
    /** @use HasFactory<TimeEntryFactory> */
    use HasFactory;

    /**
     * How long a shift may run before it is treated as forgotten rather than
     * worked.
     *
     * Somebody who clocked in on Friday afternoon and closed their laptop did
     * not work the weekend, and a total that says they did is worse than no
     * total at all. Sixteen rather than twenty-four: a day that long is already
     * past anything a working day explains, and it still leaves room for a
     * night shift that runs into the morning.
     */
    public const MAX_SHIFT_HOURS = 16;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'corrected_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still running: clocked in, not yet clocked out. */
    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Whether the times were adjusted by hand after the fact.
     */
    public function wasCorrected(): bool
    {
        return $this->corrected_at !== null;
    }

    /**
     * How long this stretch lasted, in seconds.
     *
     * A running shift is measured up to now, which is what makes the button in
     * the menu able to count. It never counts past the ceiling: see
     * MAX_SHIFT_HOURS — a shift somebody forgot to close should stop growing,
     * not keep adding an hour to their week every hour.
     */
    public function seconds(?Carbon $now = null): int
    {
        $end = $this->ended_at ?? $now ?? Carbon::now();

        $seconds = (int) $this->started_at->diffInSeconds($end, absolute: false);

        return max(0, min($seconds, self::MAX_SHIFT_HOURS * 3600));
    }

    /**
     * Which day this stretch belongs to, read on the member's own clock.
     *
     * The day it *began*, even when it ran past midnight. A night shift is one
     * evening's work and splitting it over two dates would leave both days
     * looking like half a day was worked — see StatusRule::matchesAt, which
     * settles the same question the same way for a window that crosses
     * midnight.
     */
    public function localDate(User $member): string
    {
        return $this->onMemberClock($this->started_at, $member)->toDateString();
    }

    /**
     * A stored instant as it read on this member's own clock.
     *
     * Not User::localNow(), which takes the mutable Carbon a request works with
     * — these come out of a datetime cast and are immutable. Same conversion,
     * done where the type is known rather than by widening a signature the rest
     * of the application is happy with.
     */
    public function onMemberClock(CarbonInterface $moment, User $member): CarbonInterface
    {
        return $moment->setTimezone($member->timezone);
    }

    /**
     * The shift still running, if there is one.
     *
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /**
     * Everything that had begun by the time the window closed and had not
     * finished before it opened.
     *
     * Overlap rather than containment: the shift that started yesterday evening
     * and ended this morning is part of both days it touched as far as this
     * question is concerned, and which day it counts towards is decided by
     * localDate() afterwards.
     *
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeOverlapping(Builder $query, Carbon $from, Carbon $until): void
    {
        $query->where('started_at', '<', $until)
            ->where(fn (Builder $entries) => $entries
                ->whereNull('ended_at')
                ->orWhere('ended_at', '>', $from));
    }
}
