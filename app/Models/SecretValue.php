<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * An answer somebody gave, encrypted.
 *
 * Deliberately awkward to read. There is no 'encrypted' cast and no accessor
 * that quietly hands the plaintext back, because the failure this model exists
 * to prevent is not a broken cipher — it is a value ending up somewhere nobody
 * meant it to go: an Inertia payload, a log line, a serialised model in a
 * queued job. Every one of those starts with something reading the attribute
 * without thinking about it.
 *
 * So: the column holds ciphertext, the attribute is hidden, and getting the
 * plaintext means calling reveal() — which is a thing you have to type, and
 * therefore a thing a reviewer can search for.
 *
 * @property int $id
 * @property int $secret_request_key_id
 * @property string $value The ciphertext. Not what anybody wants — see reveal().
 * @property int|null $filled_by
 * @property Carbon $filled_at
 * @property Carbon|null $revealed_at
 */
#[Fillable(['secret_request_key_id', 'value', 'filled_by', 'filled_at'])]
class SecretValue extends Model
{
    public $timestamps = false;

    /**
     * Hidden for the same reason Transfer::$token is: it must never travel in a
     * payload that did not ask for it by name. Here it must not travel at all.
     *
     * @var list<string>
     */
    protected $hidden = ['value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filled_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    /**
     * Store an answer, encrypted.
     *
     * A named constructor rather than a mutator, so that encrypting is not
     * something that happens to you — it is the only way in, and the call site
     * says so.
     */
    public static function record(
        SecretRequestKey $key,
        string $plaintext,
        ?User $filledBy,
    ): self {
        return self::create([
            'secret_request_key_id' => $key->id,
            'value' => Crypt::encryptString($plaintext),
            'filled_by' => $filledBy?->id,
            'filled_at' => now(),
        ]);
    }

    /**
     * The plaintext, and a note that it was read.
     *
     * The moment is recorded here rather than by the caller, because "who
     * looked at this" is the sort of thing that gets forgotten at one call site
     * out of three. Reading it twice moves nothing: the first look is the one
     * worth knowing about.
     */
    public function reveal(): string
    {
        if ($this->revealed_at === null) {
            $this->forceFill(['revealed_at' => now()])->save();
        }

        return Crypt::decryptString($this->value);
    }

    /** @return BelongsTo<SecretRequestKey, $this> */
    public function key(): BelongsTo
    {
        return $this->belongsTo(SecretRequestKey::class, 'secret_request_key_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filled_by');
    }
}
