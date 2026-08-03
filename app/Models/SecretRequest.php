<?php

namespace App\Models;

use Database\Factories\SecretRequestFactory;
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
 * A request for values nobody should be typing into a chat.
 *
 * The shape of it is the opposite of a message: a message is written once and
 * read by everybody, while this is written by everybody and read once, by one
 * person. Everything here follows from that — see the migration for what the
 * encryption is and is not worth, and why the expiry date is required.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int $created_by
 * @property string $title
 * @property string|null $description
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property bool $burn_after_reading
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'channel_id', 'created_by', 'title', 'description', 'expires_at', 'burn_after_reading'])]
class SecretRequest extends Model
{
    /** @use HasFactory<SecretRequestFactory> */
    use HasFactory, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'burn_after_reading' => 'boolean',
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

    /**
     * The one person who may ever read the answers.
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SecretRequestKey, $this> */
    public function keys(): HasMany
    {
        return $this->hasMany(SecretRequestKey::class)->orderBy('position');
    }

    /**
     * The answers given so far, across every key.
     *
     * @return HasManyThrough<SecretValue, SecretRequestKey, $this>
     */
    public function values(): HasManyThrough
    {
        return $this->hasManyThrough(
            SecretValue::class,
            SecretRequestKey::class,
            'secret_request_id',
            'secret_request_key_id',
        );
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether anybody may still answer it.
     *
     * Note what is not here: being fully answered. A request whose keys are all
     * filled is finished rather than broken, and the difference matters to what
     * the channel shows — "niets meer in te vullen" is not the same as "deze
     * link is dood".
     */
    public function isOpen(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired();
    }

    /** @param  Builder<SecretRequest>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
