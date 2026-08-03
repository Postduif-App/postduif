<?php

namespace App\Models;

use Database\Factories\PollFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * A question put to a channel.
 *
 * Votes are attributable, like reactions — see the migration for why, and for
 * what the interface owes people in return.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int|null $created_by
 * @property string $question
 * @property bool $allows_multiple
 * @property Carbon|null $closes_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'channel_id', 'created_by', 'question', 'allows_multiple', 'closes_at'])]
class Poll extends Model
{
    /** @use HasFactory<PollFactory> */
    use HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allows_multiple' => 'boolean',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PollOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class)->orderBy('position');
    }

    /**
     * Every vote cast on it, across the options.
     *
     * @return HasManyThrough<PollVote, PollOption, $this>
     */
    public function votes(): HasManyThrough
    {
        return $this->hasManyThrough(
            PollVote::class,
            PollOption::class,
            'poll_id',
            'poll_option_id',
        );
    }

    /**
     * Whether it still takes votes.
     *
     * Two ways to be shut and both are checked here, but they are stored apart
     * so the card can say which it was: a poll somebody closed reads
     * differently from one whose moment simply passed.
     */
    public function isClosed(): bool
    {
        return $this->closed_at !== null
            || ($this->closes_at !== null && $this->closes_at->isPast());
    }

    /**
     * How many people answered, which is not how many votes were cast — on a
     * multiple-choice poll one person may tick three boxes, and "3 of 12
     * answered" is the number anybody actually means.
     */
    public function voterCount(): int
    {
        return $this->votes()->distinct('user_id')->count('user_id');
    }

    /** @param  Builder<Poll>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('closes_at')
                ->orWhere('closes_at', '>', now()));
    }
}
