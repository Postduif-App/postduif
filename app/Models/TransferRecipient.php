<?php

namespace App\Models;

use Database\Factories\TransferRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One address a transfer was sent to, and the link that belongs to it.
 *
 * Exists so that "who may use this link" can be answered with a list of people
 * rather than with a category. What it buys over an open link is not
 * cryptography — a token is a token — but attribution: five recipients are five
 * counters, so a link that turns up somewhere it should not be can be traced to
 * whom it was handed and withdrawn without disturbing the rest.
 *
 * @property int $id
 * @property string $transfer_id
 * @property string $email
 * @property string $token
 * @property int $downloads
 * @property Carbon|null $last_downloaded_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
#[Fillable(['transfer_id', 'email', 'token'])]
class TransferRecipient extends Model
{
    /** @use HasFactory<TransferRecipientFactory> */
    use HasFactory;

    /**
     * As on the transfer itself: the token is the whole of the credential, so
     * it never travels along in a payload that did not ask for it by name.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'downloads' => 'integer',
            'last_downloaded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function freshToken(): string
    {
        return Str::random(64);
    }

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Whether this address may still fetch anything.
     *
     * Both halves have to hold: the transfer has its own limits — expiry, the
     * download ceiling, withdrawal — and this row can be stopped on its own
     * while the others carry on.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && $this->transfer->isUsable();
    }
}
