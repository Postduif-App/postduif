<?php

namespace App\Models;

use App\Enums\TransferAudience;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Collections\MediaCollection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A pile of files put aside for somebody, and a link that stands for it.
 *
 * The twin of a message attachment, for the case where there is no
 * conversation: the recipient may have no account, may not be in the workspace,
 * and may be a person you will never speak to again. What takes the place of
 * all that trust is the link, and the limits on it — until when, how often, and
 * (see TransferAudience) by whom.
 *
 * Explicitly not soft-deleted. A message keeps its files after deletion so a
 * thread stays readable; a transfer that is gone is gone, and its bytes should
 * go with it — which is what the media library does on a hard delete.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $token
 * @property TransferAudience $audience
 * @property string|null $password
 * @property string|null $title
 * @property string|null $message
 * @property Carbon $expires_at
 * @property int|null $max_downloads
 * @property int $downloads
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'created_by', 'token', 'audience', 'password', 'title', 'message', 'expires_at', 'max_downloads'])]
class Transfer extends Model implements HasMedia
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory, HasUlids, InteractsWithMedia;

    /** The one collection a transfer has: what is being sent. */
    public const FILES = 'files';

    /**
     * The token is the whole of the proof that these files are for you, so it
     * never travels along in a payload that did not ask for it by name.
     *
     * @var list<string>
     */
    protected $hidden = ['token', 'password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience' => TransferAudience::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_downloads' => 'integer',
            'downloads' => 'integer',
        ];
    }

    /**
     * Where the files hang.
     *
     * On the private disk, like everything else here, and reached only through
     * a route that checks the token and the limits first. That is more than
     * habit for this one: a public URL would outlive the expiry date, the
     * download ceiling and the withdrawal all at once, which is every limit the
     * feature has.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::FILES);
    }

    /*
     * No conversions, deliberately — unlike Message, which makes a small copy
     * of every image.
     *
     * A message list shows dozens of attachments at once, so a thumbnail is
     * what keeps a channel full of screenshots quick to open. A transfer page
     * shows one pile of files that somebody came to fetch, and a preview buys
     * nothing there. What it would cost is real: this collection takes any file
     * type at all, and handing an installer or an SVG to the image driver is a
     * failed upload rather than a missing thumbnail.
     */

    /**
     * The secret in the link. Long and random rather than derived from the
     * transfer: it is handed to people with no account, and for them it is the
     * only credential there is.
     */
    public static function freshToken(): string
    {
        return Str::random(64);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The addresses this was sent to, when it was sent to addresses at all.
     *
     * Empty for the other two audiences, and that is not a missing list: an
     * open link has no recipients because it was never addressed to anybody.
     *
     * @return HasMany<TransferRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(TransferRecipient::class);
    }

    /**
     * Every time something was handed over, newest first.
     *
     * @return HasMany<TransferDownload, $this>
     */
    public function downloadLog(): HasMany
    {
        return $this->hasMany(TransferDownload::class)->latest('created_at');
    }

    /**
     * What is in it, in the order it was uploaded.
     *
     * @return MediaCollection<int, Media>
     */
    public function files(): MediaCollection
    {
        return $this->getMedia(self::FILES);
    }

    /** What the whole thing weighs, which is what a recipient wants told. */
    public function size(): int
    {
        return (int) $this->files()->sum('size');
    }

    /**
     * Whether there is something to know beside the link.
     *
     * Kept apart from the three reasons a transfer stops working: a locked
     * transfer is perfectly alive, and the recipient is one step short rather
     * than out of luck. isUsable() deliberately says nothing about it.
     */
    public function isLocked(): bool
    {
        return $this->password !== null;
    }

    /**
     * Where the fact that this visitor got past the lock is remembered.
     *
     * Keyed by the transfer, so being let into one says nothing about another —
     * a single "unlocked" flag would turn one password into a key for every
     * transfer the same browser ever visits.
     */
    public function unlockedSessionKey(): string
    {
        return 'transfer-unlocked.'.$this->id;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** No maximum means no ceiling — that is what null is doing there. */
    public function isExhausted(): bool
    {
        return $this->max_downloads !== null && $this->downloads >= $this->max_downloads;
    }

    /**
     * Whether the link still hands anything over.
     *
     * The three reasons stay apart on purpose: the landing page has to say
     * which one it is, because "ask them to send it again" and "ask them why
     * they withdrew it" are different next steps for whoever is holding it.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired() && ! $this->isExhausted();
    }

    /**
     * The transfers still doing their job.
     *
     * Written in SQL rather than filtered through isUsable() in PHP so it can
     * be counted and paged. The null on max_downloads is spelled out because in
     * SQL a null compares to nothing, and "no ceiling" has to read as "not
     * reached".
     *
     * @param  Builder<Transfer>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn (Builder $query) => $query
                ->whereNull('max_downloads')
                ->orWhereColumn('downloads', '<', 'max_downloads'));
    }
}
