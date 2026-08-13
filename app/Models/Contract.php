<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A PDF somebody wants signed, and everything drawn over it.
 *
 * The cousin of Transfer: a document handed to people who may have no account
 * here, reached by a link that is the whole of their credential. What is
 * different is the direction. A transfer hands bytes out and is finished; a
 * contract hands a document out and waits for something to come back, which is
 * why this one has a status and a deadline where a transfer has a download
 * counter.
 *
 * Not soft-deleted, and the reasoning splits: a draft nobody sent is a scrap of
 * paper, but a completed contract is the piece of evidence the feature exists to
 * produce. What keeps the second from being deleted on a timer is
 * PruneContracts, which refuses to touch anything Completed — see
 * ContractStatus::isEvidence().
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $title
 * @property string|null $message
 * @property int|null $notify_channel_id
 * @property string|null $callback_url
 * @property string|null $callback_secret
 * @property ContractStatus $status
 * @property bool $is_template
 * @property int|null $required_signers
 * @property int $page_count
 * @property string|null $source_hash
 * @property Carbon|null $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $render_failed_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
/*
 * The three stamps are fillable beside the columns somebody types into, and it
 * is worth saying why: they are not user input, they are written by the actions
 * that own the transitions — see SignContract and PruneContracts. Leaving them
 * out meant update(['completed_at' => now()]) silently doing nothing, which is
 * the quietest possible way for a contract to end up finished with no record of
 * when.
 */
/*
 * The two callback columns are fillable beside them for a different reason
 * again: they are never typed on a screen, only ever set by the API endpoint
 * that accepts a contract from another system, which is exactly the caller that
 * builds a contract from an array in one go.
 */
#[Fillable([
    'workspace_id', 'created_by', 'title', 'message', 'notify_channel_id', 'status', 'page_count',
    'source_hash', 'expires_at', 'completed_at', 'cancelled_at', 'render_failed_at',
    'is_template', 'required_signers', 'callback_url', 'callback_secret',
])]
class Contract extends Model implements HasMedia
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory, HasUlids, InteractsWithMedia;

    /**
     * The PDF as it will be signed.
     *
     * "source" rather than "original", because it is deliberately not the
     * original: what lands here has been through Ghostscript — see
     * NormalisePdf — so the bytes on disk are a rewrite of what the author
     * uploaded. The hash that proves what was signed is taken over this file,
     * not over the upload, for exactly that reason.
     */
    public const SOURCE = 'source';

    /**
     * The finished article: the source with every answer painted onto it and
     * the audit trail bound to the back.
     *
     * A second collection rather than a replacement, because the two have to
     * coexist. The source is what was agreed to and what the hash covers; the
     * signed copy is what people download. Overwriting the first with the
     * second would destroy the only thing the hash can be checked against.
     */
    public const SIGNED = 'signed';

    /**
     * Take the signers down by hand before the database takes them down for us.
     *
     * The foreign key already says cascadeOnDelete, so the rows would go
     * either way — but a cascade happens inside the database and fires no
     * Eloquent events, and the signatures hang on those rows through the media
     * library, which only removes files on the model's own delete event.
     * Without this, deleting a contract leaves every signature PNG on the disk
     * forever with an orphaned media row pointing at it.
     *
     * The same trap PruneTransfers spells out for a mass delete, one layer
     * further down: there it is the query builder that skips the events, here it
     * is the database.
     */
    protected static function booted(): void
    {
        static::deleting(function (Contract $contract): void {
            $contract->signers()->each(fn (ContractSigner $signer) => $signer->delete());
        });
    }

    /**
     * The one thing on this row that must never leave it by accident.
     *
     * Encrypted at rest and readable through the cast, which is what the
     * delivery job needs — but it is the key somebody verifies our signature
     * with, and a row serialised whole would put it in a response nobody meant
     * to send it in. Hidden here rather than remembered at each call site,
     * because the call site that forgets is the one that matters.
     *
     * @var list<string>
     */
    protected $hidden = ['callback_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            /*
             * Encrypted at rest, like a subscription's secret and for the same
             * reason: it has to be produced in full to sign a body with, so
             * there is nothing one-way to store, and a copied database row
             * should not be enough to forge our signature.
             */
            'callback_secret' => 'encrypted',
            'is_template' => 'boolean',
            'required_signers' => 'integer',
            'page_count' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'render_failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Both collections on the private disk, both single-file.
     *
     * Private is not a habit here, it is the feature: a contract is by
     * definition not for everybody who guesses the URL, and a public disk would
     * put a signed agreement one lucky guess away from a stranger. Everything
     * that reads these goes through a route with ContractPolicy on it.
     *
     * singleFile because a second upload means the author changed their mind
     * about which document this is, not that the contract now has two — and a
     * contract with two source PDFs is a contract nobody can say was signed.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::SOURCE)->singleFile();
        $this->addMediaCollection(self::SIGNED)->singleFile();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The language the mails about this contract are written in.
     *
     * A signer has no account and no preference — that is the whole shape of
     * this feature — so there is nobody to ask. What is left is the person who
     * sent it, which is also the closest thing to right: somebody who works in
     * Dutch is writing to a client they picked up the phone to in Dutch.
     *
     * Asked out loud rather than left to App::getLocale(), because half of
     * these mails leave from somewhere that has no locale worth having. The
     * signed copy goes out from a queued job, and a reminder from the
     * scheduler; both run in the configured default, so without this the same
     * contract would ask in one language and confirm in another.
     *
     * Falls back to the application default when the author is gone — a
     * contract outlives whoever sent it, see created_by — or never chose.
     */
    public function mailLocale(): string
    {
        return $this->author?->preferredLocale() ?? (string) config('app.locale');
    }

    /**
     * Where news about this contract is posted when there is nobody to DM.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function notifyChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'notify_channel_id');
    }

    /**
     * The boxes drawn over the document, in the order they were laid down.
     *
     * @return HasMany<ContractField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(ContractField::class)->orderBy('page')->orderBy('position');
    }

    /**
     * Who was asked, in the order they were named.
     *
     * @return HasMany<ContractSigner, $this>
     */
    public function signers(): HasMany
    {
        return $this->hasMany(ContractSigner::class)->orderBy('signing_order');
    }

    /**
     * Every answer given by anybody, for the renderer and the detail screen.
     *
     * @return HasManyThrough<ContractFieldValue, ContractField, $this>
     */
    public function fieldValues(): HasManyThrough
    {
        return $this->hasManyThrough(ContractFieldValue::class, ContractField::class);
    }

    /** The PDF as it will be signed, or null before one has been uploaded. */
    public function source(): ?Media
    {
        return $this->getFirstMedia(self::SOURCE);
    }

    /** The finished article, or null while it is still being made. */
    public function signedCopy(): ?Media
    {
        return $this->getFirstMedia(self::SIGNED);
    }

    /**
     * Whether the signed copy is still being made, went wrong, or is ready.
     *
     * Three answers rather than a boolean, because the overview has to say
     * three different things — and the middle one is the reason this exists:
     * a contract whose PDF could not be composed is still signed, and telling
     * somebody "nog even geduld" about a job that gave up two days ago is worse
     * than telling them nothing.
     *
     * @return 'ready'|'failed'|'pending'|'none'
     */
    public function signedCopyState(): string
    {
        if (! $this->status->isEvidence()) {
            return 'none';
        }

        if ($this->signedCopy() !== null) {
            return 'ready';
        }

        return $this->render_failed_at === null ? 'pending' : 'failed';
    }

    /**
     * Whether the document is on the row yet.
     *
     * A contract exists for a moment without one — the row is created, then the
     * bytes are stored on it — and everything that lays boxes over pages has to
     * know the difference between "nog niet" and "leeg".
     */
    public function hasSource(): bool
    {
        return $this->source() !== null;
    }

    /**
     * Whether this row is kept to be sent again rather than sent itself.
     *
     * Asked in a great many places, all of them saying the same thing: a
     * template is a contract that is deliberately never going anywhere, so
     * anything that would put it in front of a signer, count it as outstanding
     * work or tidy it away on a timer has to step around it.
     */
    public function isTemplate(): bool
    {
        return $this->is_template;
    }

    /**
     * The author's own row on a template, or null when they do not sign along.
     *
     * A template holds at most this one signer. The people it will eventually
     * go to have no rows here — they do not exist yet, and inventing
     * placeholders with made-up addresses would put rows in contract_signers
     * that look exactly like people who were asked and never answered.
     *
     * Which means the author is always position zero when they take part, and
     * the recipients follow at one and up. That is the whole of the numbering,
     * and InstantiateTemplate leans on it to copy the boxes across without
     * touching a single signer_index.
     */
    public function templateSigner(): ?ContractSigner
    {
        return $this->signers->first();
    }

    /**
     * How many parties the finished contract has: the recipients, plus the
     * author when they signed along.
     *
     * The number the boxes were laid out against, and the reason it is derived
     * rather than stored: storing it would mean two columns that have to agree
     * about the same document, and the moment the author adds or removes their
     * own signature they would stop agreeing.
     */
    public function partyCount(): int
    {
        return ($this->required_signers ?? 0) + ($this->templateSigner() === null ? 0 : 1);
    }

    /**
     * Whether this template can actually be sent to somebody.
     *
     * Four things have to be true, and each of them is a way a half-built
     * template would fail at the worst moment — in an API call from somebody
     * else's system, which cannot go and finish it. There is no document to
     * sign, nobody knows how many people to ask, there is nothing to fill in,
     * or the author said they would sign along and never did, in which case
     * every derived contract would go out with an empty box where their
     * signature was promised.
     */
    public function isReadyToSend(): bool
    {
        if (! $this->is_template || $this->required_signers === null || ! $this->hasSource()) {
            return false;
        }

        if ($this->fields->isEmpty()) {
            return false;
        }

        $author = $this->templateSigner();

        return $author === null || $author->hasSigned();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether a link handed out for this contract still opens anything.
     *
     * Both halves have to hold, and the expiry is asked here rather than left
     * to the status column because the status only turns Expired when the prune
     * command next runs. A deadline that passed an hour ago has passed, whatever
     * the column still says.
     *
     * A template answers yes while it is still a draft, which is the one place
     * that sentence is not a contradiction. Its author has to put their
     * signature on it once, and they do that through the same page every other
     * signer uses — so the page has to open. There is no deadline to check
     * because a template never has one, and nothing else can reach it: the only
     * link that opens it is the author's own, and there is only ever the one
     * signer row.
     */
    public function isSignable(): bool
    {
        if ($this->is_template) {
            return $this->status === ContractStatus::Draft;
        }

        return $this->status->isSignable() && ! $this->hasExpired();
    }

    /** How many of the people asked have actually signed. */
    public function signedCount(): int
    {
        return $this->signers->whereNotNull('signed_at')->count();
    }

    /**
     * Whether everybody who was asked has now either signed or refused.
     *
     * A refusal counts as an answer: a contract one person declined is finished
     * business, and waiting for it forever would leave it in the author's list
     * as though somebody still owed them something.
     *
     * Asked of the loaded collection rather than in SQL on purpose — it is
     * called at the end of signing, when the signers are already in memory and
     * inside the transaction that just stamped one of them.
     */
    public function isFullyAnswered(): bool
    {
        return $this->signers->every(
            fn (ContractSigner $signer): bool => $signer->hasAnswered()
        );
    }

    /**
     * Close this contract if nobody is left to hear from.
     *
     * On the model rather than in the two actions that call it, because it is
     * one rule about a contract rather than a step in either of them: the last
     * person answering is what finishes it, and whether they answered by
     * signing or by refusing makes no difference to that.
     *
     * A refusal does count — see isFullyAnswered. A contract one person
     * declined is finished business, and leaving it open would keep telling its
     * author that somebody still owes them something.
     */
    public function settleIfEverybodyHasAnswered(): bool
    {
        /*
         * A template is never finished, however completely it has been filled
         * in. Its one signer signing is the moment it becomes usable, not the
         * moment it is done with — and a Completed template would be evidence
         * by ContractStatus::isEvidence(), which is a strong claim to make
         * about a document nobody has been shown.
         */
        if ($this->is_template) {
            return false;
        }

        $fresh = $this->fresh(['signers']);

        if ($fresh === null || ! $fresh->isFullyAnswered()) {
            return false;
        }

        $fresh->update([
            'status' => ContractStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->setRawAttributes($fresh->getAttributes(), sync: true);

        return true;
    }

    /**
     * The contracts still asking something of somebody.
     *
     * Written in SQL rather than filtered through the enum in PHP so it can be
     * counted and paged. The expiry is spelled out because a null there means
     * "no deadline" and in SQL a null compares to nothing — without the explicit
     * whereNull, every contract without a deadline would drop out of the list.
     *
     * @param  Builder<Contract>  $query
     */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('is_template', false)
            ->whereIn('status', [ContractStatus::Draft->value, ContractStatus::Sent->value])
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    /**
     * The documents kept to be sent again.
     *
     * @param  Builder<Contract>  $query
     */
    public function scopeTemplates(Builder $query): void
    {
        $query->where('is_template', true);
    }

    /**
     * Everything that is an actual contract rather than a mould for one.
     *
     * Its own scope, and used by every list and count there is, because
     * forgetting it is silent: a template would simply appear among the drafts,
     * looking for all the world like a contract somebody forgot to send.
     *
     * @param  Builder<Contract>  $query
     */
    public function scopeRealContracts(Builder $query): void
    {
        $query->where('is_template', false);
    }
}
