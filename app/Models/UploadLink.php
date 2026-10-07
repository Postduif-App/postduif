<?php

namespace App\Models;

use Database\Factories\UploadLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Room put aside for somebody outside to hand files to us.
 *
 * A transfer turned around. Everything that makes a transfer safe to hand out
 * holds here too — a long random token, an expiry date, an optional password,
 * withdrawal — with one difference that shapes the rest: what arrives is
 * written by a stranger. So the ceiling on what the link will take is on the
 * lot, across every submission, and the files only ever leave again as an
 * attachment to somebody who is signed in.
 *
 * Not soft-deleted, like a transfer: when it goes, its bytes go with it.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property int|null $notify_channel_id
 * @property string $token
 * @property string $title
 * @property string|null $message
 * @property string|null $password
 * @property Carbon $expires_at
 * @property int|null $max_uploads
 * @property int $uploads
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'created_by', 'notify_channel_id', 'token', 'title', 'message', 'password', 'expires_at', 'max_uploads'])]
class UploadLink extends Model
{
    /** @use HasFactory<UploadLinkFactory> */
    use HasFactory, HasUlids;

    /**
     * The token is the whole of the permission to write into this workspace,
     * so it never travels along in a payload that did not ask for it by name.
     *
     * @var list<string>
     */
    protected $hidden = ['token', 'password'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uploads' => 'integer',
            'uploads' => 'integer',
        ];
    }

    /**
     * Take the submissions down one at a time before the row goes.
     *
     * The foreign key would cascade the rows on its own, but a cascade in the
     * database fires no model events — and the media library removes the files
     * on exactly that event. Without this, deleting a link would leave every
     * byte a customer ever sent on the disk with nothing pointing at it.
     */
    protected static function booted(): void
    {
        static::deleting(function (UploadLink $link): void {
            $link->submissions()->each(fn (UploadLinkSubmission $submission) => $submission->delete());
        });
    }

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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Channel, $this> */
    public function notifyChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'notify_channel_id');
    }

    /**
     * Everything sent in so far, newest first.
     *
     * @return HasMany<UploadLinkSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(UploadLinkSubmission::class)->latest('created_at');
    }

    /**
     * What has been sent in, in bytes, across every submission.
     *
     * Asked of the media table rather than summed over loaded models, because
     * it is asked on the way in — before a stranger's upload is accepted — and
     * the answer has to be the one on disk, not the one in memory.
     */
    public function receivedBytes(): int
    {
        return (int) Media::query()
            ->where('model_type', (new UploadLinkSubmission)->getMorphClass())
            ->whereIn('model_id', $this->submissions()->select('id'))
            ->sum('size');
    }

    public function isLocked(): bool
    {
        return $this->password !== null;
    }

    /** Keyed by the link, so being let into one opens no other. */
    public function unlockedSessionKey(): string
    {
        return 'upload-link-unlocked.'.$this->id;
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
        return $this->max_uploads !== null && $this->uploads >= $this->max_uploads;
    }

    /** Whether the link still takes anything in. */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired() && ! $this->isExhausted();
    }

    /**
     * One word for where the link stands, the same shape a transfer uses so
     * the screens can share their wording.
     */
    public function state(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->hasExpired() => 'expired',
            $this->isExhausted() => 'exhausted',
            default => 'usable',
        };
    }

    /**
     * The links still taking files in, in SQL so it can be counted and paged.
     *
     * @param  Builder<UploadLink>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->where(fn (Builder $query) => $query
                ->whereNull('max_uploads')
                ->orWhereColumn('uploads', '<', 'max_uploads'));
    }
}
