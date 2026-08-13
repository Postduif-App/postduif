<?php

namespace App\Models;

use Database\Factories\ContractWebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An address this workspace wants told about its contracts.
 *
 * The outward-facing twin of Webhook. That one is a door: somebody else holds a
 * token and posts to us. This one is a correspondent: we hold a secret and post
 * to them. Everything about the credential turns around with the direction — a
 * token can be hashed because we only ever have to recognise it, while a secret
 * has to be produced in full to sign a body with, so it is encrypted rather than
 * digested. See hashToken on Webhook for the other side of that argument.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property int|null $created_by
 * @property Carbon|null $last_delivered_at
 * @property Carbon|null $last_failed_at
 * @property int|null $last_status
 * @property Carbon|null $disabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'url', 'events', 'created_by'])]
class ContractWebhook extends Model
{
    /** @use HasFactory<ContractWebhookFactory> */
    use HasFactory;

    /**
     * Somebody put their name to a contract.
     *
     * Fires once per signer, including the last one — a contract with three
     * parties is three of these, and the one that finished it is not special
     * here. What is special about the last is that Completed follows it.
     */
    public const EVENT_SIGNED = 'signed';

    /** Somebody read it and said no. */
    public const EVENT_DECLINED = 'declined';

    /**
     * Nobody is left to hear from, and the signed PDF is on disk.
     *
     * Deliberately not "the last person signed": a contract one party refused is
     * finished business too, and a system waiting for Completed should not hang
     * forever because somebody said no.
     */
    public const EVENT_COMPLETED = 'completed';

    /**
     * Every event there is, in the order a screen should offer them.
     *
     * The order is the life of a contract rather than alphabetical, because
     * that is the order somebody reads three checkboxes in when deciding which
     * of them they want.
     *
     * @var list<string>
     */
    public const EVENTS = [self::EVENT_SIGNED, self::EVENT_DECLINED, self::EVENT_COMPLETED];

    /**
     * Long enough that guessing a signature is hopeless, and prefixed so a
     * secret that turns up in somebody's log file is recognisable for what it
     * is — and for whose it is.
     */
    private const PREFIX = 'whs_';

    private const RANDOM_LENGTH = 48;

    /**
     * The secret never rides along in a serialisation.
     *
     * Showing it is a deliberate act — the settings screen asks for it by name
     * so that somebody can paste it into the receiving system — and never a
     * side effect of handing a model to a response.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'last_delivered_at' => 'datetime',
            'last_failed_at' => 'datetime',
            'last_status' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Mint a secret, put it on the row, and hand back the plain value.
     *
     * Both at once, unlike Webhook::regenerateToken, which has a hash to keep
     * beside the token — here the stored value and the shown value are the same
     * string, and the encryption is a property of the column rather than a
     * second form of it.
     *
     * Rotating is the only repair for a secret that leaked, and it is a hard
     * cut: every delivery after this one is signed with the new secret, so the
     * far end will reject them until somebody pastes it across. That is the
     * point — a rotation that kept honouring the old secret for a while would
     * leave whoever copied it a window to use it in.
     */
    public function regenerateSecret(): string
    {
        $secret = self::PREFIX.Str::random(self::RANDOM_LENGTH);

        $this->forceFill(['secret' => $secret]);

        return $secret;
    }

    /**
     * Whether this subscription asked to hear about this kind of news.
     *
     * A string rather than an enum, and on purpose: the same three names are the
     * `event` field of the payload we post, so keeping one vocabulary means the
     * value a beheerder ticked on a screen is the value the receiving system
     * matches on, with nothing in between to translate it wrongly.
     */
    public function wants(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * The subscriptions that are still listening.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }
}
