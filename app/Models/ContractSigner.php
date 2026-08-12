<?php

namespace App\Models;

use Database\Factories\ContractSignerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One person asked to sign, and the link that belongs to them.
 *
 * The twin of TransferRecipient, and here the reason for a row per person is
 * stronger than attribution alone: a shared link could tell you that somebody
 * signed but not who, and who signed is the entire point of a contract. Five
 * signers are five tokens, five moments and five IP addresses.
 *
 * @property string $id
 * @property string $contract_id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string $token
 * @property int $signing_order
 * @property Carbon|null $opened_at
 * @property Carbon|null $signed_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $reminded_at
 * @property string|null $decline_reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['contract_id', 'user_id', 'name', 'email', 'token', 'signing_order'])]
class ContractSigner extends Model implements HasMedia
{
    /** @use HasFactory<ContractSignerFactory> */
    use HasFactory, HasUlids, InteractsWithMedia;

    /**
     * The drawing itself, and the smaller one.
     *
     * Two collections rather than one image per box, because a contract that
     * wants initials at the foot of nine pages should not ask anybody to draw
     * nine times. What each of those boxes stores is the fact that it was dealt
     * with; what gets painted into all nine is this one image.
     *
     * Kept apart from the signature because they are not the same drawing — a
     * full signature scaled down to a corner is a smudge, which is the reason
     * initials exist as a separate thing in the first place.
     */
    public const SIGNATURE = 'signature';

    public const INITIALS = 'initials';

    /**
     * As on a transfer recipient: the token is the whole of the credential, so
     * it never travels along in a payload that did not ask for it by name.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signing_order' => 'integer',
            'opened_at' => 'datetime',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * Both on the private disk, both single-file.
     *
     * A signature is a picture of a person's name, which is the sort of thing
     * that should not be lying on a public disk under a guessable path. Single
     * because drawing again means "dat was niet goed", not "ik heb er nu twee".
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::SIGNATURE)->singleFile();
        $this->addMediaCollection(self::INITIALS)->singleFile();
    }

    /**
     * The secret in the link. Long and random rather than derived from the row:
     * it is handed to people with no account, and for them it is the only
     * credential there is.
     */
    public static function freshToken(): string
    {
        return Str::random(64);
    }

    /**
     * Where this person goes to fill the thing in.
     *
     * Built from the token rather than from the row's id, which is the whole
     * design: the id says nothing to anybody who does not already hold the
     * link, and the token is what stands in for having been let in.
     */
    public function signUrl(): string
    {
        return route('contracts.sign.show', $this->token);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * The colleague this is, when it is one at all.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What this person put in each of their boxes.
     *
     * @return HasMany<ContractFieldValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ContractFieldValue::class);
    }

    public function signature(): ?Media
    {
        return $this->getFirstMedia(self::SIGNATURE);
    }

    public function initials(): ?Media
    {
        return $this->getFirstMedia(self::INITIALS);
    }

    public function hasSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function hasDeclined(): bool
    {
        return $this->declined_at !== null;
    }

    /**
     * Whether this person has said anything at all yet, either way.
     *
     * What "is iedereen langs geweest" is asked in terms of. A refusal is an
     * answer — see Contract::isFullyAnswered().
     */
    public function hasAnswered(): bool
    {
        return $this->hasSigned() || $this->hasDeclined();
    }

    /**
     * Whether this person's link still leads to something they can fill in.
     *
     * Both halves have to hold: the contract has its own reasons to be closed —
     * withdrawn, expired, already complete — and this row is spent on its own
     * once its holder has answered.
     */
    public function canStillSign(): bool
    {
        return ! $this->hasAnswered() && $this->contract->isSignable();
    }

    /**
     * Whether this person may be nudged again yet.
     *
     * A day, counted from the last nudge rather than from the invitation, so
     * pressing the button twice in a morning sends one mail. What this is
     * guarding against is not accidental double-clicks — those are cheap — but
     * somebody using the button as a way to sit on a colleague's inbox.
     *
     * Somebody who has already answered is never remindable, whatever the
     * dates say: there is nothing left to ask them.
     */
    public function canBeRemindedAt(?Carbon $moment = null): bool
    {
        if (! $this->canStillSign()) {
            return false;
        }

        return $this->reminded_at === null
            || $this->reminded_at->addDay()->isBefore($moment ?? now());
    }

    /**
     * Whether a token from a URL is this signer's.
     *
     * hash_equals rather than ===, because a plain comparison returns as soon as
     * two bytes differ and the time it took says how far the guess got. That is
     * a small leak on a 64-character random string, but it is free to close and
     * this is the one credential the outside world holds.
     */
    public function tokenMatches(string $token): bool
    {
        return hash_equals($this->token, $token);
    }
}
