<?php

namespace App\Models;

use Database\Factories\SecretRequestKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One thing being asked for: DB_PASSWORD, MAIL_USERNAME.
 *
 * @property int $id
 * @property string $secret_request_id
 * @property string $name
 * @property string|null $hint
 * @property int $position
 */
#[Fillable(['secret_request_id', 'name', 'hint', 'position'])]
class SecretRequestKey extends Model
{
    /** @use HasFactory<SecretRequestKeyFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return BelongsTo<SecretRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(SecretRequest::class, 'secret_request_id');
    }

    /**
     * The answer, if it has been given.
     *
     * HasOne rather than HasMany, matching the unique index: a key is answered
     * once, and a second answer is refused by the database rather than by
     * whichever piece of code happened to look first.
     *
     * @return HasOne<SecretValue, $this>
     */
    public function value(): HasOne
    {
        return $this->hasOne(SecretValue::class);
    }

    public function isAnswered(): bool
    {
        return $this->value()->exists();
    }
}
