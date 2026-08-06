<?php

namespace App\Models;

use Database\Factories\HuddleParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Somebody in a huddle, or somebody who was.
 *
 * A model rather than a pivot on a belongsToMany, because both of its columns
 * are the point: when you came in, and whether you are still here. A pivot with
 * two timestamps on it is a model that has not admitted it yet.
 *
 * @property int $id
 * @property int $huddle_id
 * @property int $user_id
 * @property Carbon $joined_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $left_at
 */
#[Fillable(['huddle_id', 'user_id', 'joined_at', 'last_seen_at', 'left_at'])]
class HuddleParticipant extends Model
{
    /** @use HasFactory<HuddleParticipantFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Huddle, $this> */
    public function huddle(): BelongsTo
    {
        return $this->belongsTo(Huddle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
