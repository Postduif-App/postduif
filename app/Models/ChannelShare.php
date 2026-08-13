<?php

namespace App\Models;

use Database\Factories\ChannelShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A channel one workspace has opened to another.
 *
 * Read far more often than it is written — every policy question about a
 * channel with a share on it ends here — so the two questions that matter are
 * methods rather than comparisons callers write out: whether the arrangement is
 * live, and whether it lets the other side speak.
 *
 * @property int $id
 * @property int $channel_id
 * @property int $workspace_id
 * @property int|null $invited_by
 * @property int|null $accepted_by
 * @property bool $can_post
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['channel_id', 'workspace_id', 'invited_by', 'accepted_by', 'can_post', 'accepted_at', 'declined_at', 'revoked_at'])]
class ChannelShare extends Model
{
    /** @use HasFactory<ChannelShareFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'can_post' => 'boolean',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The rule the database could not be given: a workspace is never a guest in
     * a channel it already owns.
     *
     * Refused rather than quietly dropped. A caller that got here is working
     * from a wrong idea of who owns the channel, and letting the write succeed
     * would hand every member of the host workspace a second way into a private
     * channel — one that goes round the members table, which is the only place
     * anybody looks when they ask who can read something.
     */
    protected static function booted(): void
    {
        static::saving(function (self $share): void {
            $owner = Channel::query()->whereKey($share->channel_id)->value('workspace_id');

            if ($owner !== null && (int) $owner === (int) $share->workspace_id) {
                throw new RuntimeException('A channel cannot be shared with the workspace that owns it.');
            }
        });
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The workspace being let in — never the one that owns the channel.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<User, $this> */
    public function accepter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * Accepted and not since called off — the only state in which this row
     * grants anything.
     */
    public function isLive(): bool
    {
        return $this->accepted_at !== null && $this->revoked_at === null;
    }

    /**
     * Offered and not yet answered.
     *
     * A share the host withdrew before the other side got to it is not pending:
     * revoked_at wins over an unanswered invitation, or a workspace could
     * accept its way into a channel that had already been closed to it.
     */
    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->declined_at === null
            && $this->revoked_at === null;
    }

    /**
     * May the other side write here, as this arrangement stands.
     *
     * Both halves in one place because they are always asked together, and the
     * pair is easy to get wrong the other way round: can_post on a share that
     * was revoked this morning is still true, and it means nothing.
     */
    public function permitsPosting(): bool
    {
        return $this->isLive() && $this->can_post;
    }

    /**
     * The shares that actually grant access.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotNull('accepted_at')->whereNull('revoked_at');
    }

    /**
     * The ones waiting on an answer from the invited workspace.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->whereNull('revoked_at');
    }
}
