<?php

namespace App\Models;

use Database\Factories\ReminderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something somebody asked to be reminded of.
 *
 * Nobody else's business, ever. A reminder is not a flag on the message and
 * says nothing to the person who wrote it — it is a note to yourself about
 * somebody else's sentence, and the whole reason it is usable is that setting
 * one is not an act anybody can see.
 *
 * @property int $id
 * @property int $user_id
 * @property string $message_id
 * @property int $channel_id
 * @property string|null $note
 * @property Carbon $remind_at
 * @property Carbon|null $delivered_at
 */
#[Fillable(['user_id', 'message_id', 'channel_id', 'note', 'remind_at'])]
class Reminder extends Model
{
    /** @use HasFactory<ReminderFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** Still to come. */
    public function isPending(): bool
    {
        return $this->delivered_at === null;
    }

    /**
     * The ones whose moment has arrived and that have not gone off yet.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('delivered_at')->where('remind_at', '<=', now());
    }

    /**
     * What somebody still has coming, soonest first.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePendingFor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)
            ->whereNull('delivered_at')
            ->orderBy('remind_at');
    }
}
