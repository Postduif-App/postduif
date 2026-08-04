<?php

namespace App\Models;

use Database\Factories\SentSecretFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A secret handed to somebody, readable once.
 *
 * The mirror of SecretRequest, and the opposite shape: that one is written by
 * everybody and read once by one person, this one is written once by one person
 * and read once by one other.
 *
 * Note what this model cannot do, unlike SecretValue: there is no reveal(). It
 * has no key and never had one — the ciphertext goes back out exactly as it came
 * in, and the browser holding the fragment is the only thing in the world that
 * can turn it into words. See the migration for why that was chosen.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $channel_id
 * @property int $created_by
 * @property int|null $recipient_id
 * @property string $label
 * @property string $ciphertext Unreadable here, and meant to stay that way.
 * @property string $iv
 * @property string|null $password_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revealed_at
 * @property Carbon|null $created_at
 */
#[Fillable([
    'workspace_id', 'channel_id', 'created_by', 'recipient_id',
    'label', 'ciphertext', 'iv', 'password_hash', 'expires_at',
])]
class SentSecret extends Model
{
    /** @use HasFactory<SentSecretFactory> */
    use HasFactory, HasUlids;

    /**
     * Hidden for the same reason SecretValue::$value is: neither may travel in
     * a payload that did not ask for it by name. The ciphertext is useless
     * without the fragment, but "useless to whoever has it" is not a thing to
     * start relying on by accident.
     *
     * @var list<string>
     */
    protected $hidden = ['ciphertext', 'iv', 'password_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revealed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The channel it was announced in, or null when it was never announced
     * anywhere — see the migration that made this optional.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Who it was meant for, or null for a link made without naming anybody.
     *
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function isRevealed(): bool
    {
        return $this->revealed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Whether there is still something here to hand over. */
    public function isPending(): bool
    {
        return ! $this->isRevealed() && ! $this->isExpired();
    }

    public function needsPassword(): bool
    {
        return $this->password_hash !== null;
    }

    /**
     * Why it is no longer available, or 'pending' while it still is.
     *
     * One value worked out here rather than three booleans sent to the browser:
     * the retrieval screen says one sentence, and deciding which one is not a
     * thing to re-derive in two places.
     */
    public function state(): string
    {
        return match (true) {
            $this->isRevealed() => 'revealed',
            $this->isExpired() => 'expired',
            default => 'pending',
        };
    }

    /**
     * Everything one person put aside, newest first.
     *
     * Deliberately unfiltered: the list this feeds is where somebody goes to
     * check whether a link was ever picked up, and a page that quietly dropped
     * the expired ones would answer "no" and "it is gone" with the same blank
     * space.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSentBy(Builder $query, User $sender): void
    {
        /*
         * The id breaks the tie, and it is not decoration: two links made in
         * the same second are otherwise in whatever order the database felt
         * like, and a list that reshuffles between page loads is one nobody can
         * point at. A ULID sorts by the moment it was made, so this is the same
         * ordering carried to a finer grain rather than a second one.
         */
        $query->where('created_by', $sender->id)
            ->latest('created_at')
            ->orderByDesc('id');
    }

    /**
     * Secrets that have nothing left worth keeping: read, or long past their
     * moment.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeSpent(Builder $query): void
    {
        $query->whereNotNull('revealed_at')->orWhere('expires_at', '<', now());
    }
}
