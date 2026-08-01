<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * @property string $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int|null $user_id
 * @property int|null $webhook_id
 * @property string|null $bot_name
 * @property string|null $forwarded_from
 * @property string|null $parent_id
 * @property string|null $quoted_message_id
 * @property string $body
 * @property int $reply_count
 * @property Carbon|null $pinned_at
 * @property int|null $pinned_by
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 */
#[Fillable(['id', 'workspace_id', 'channel_id', 'user_id', 'webhook_id', 'bot_name', 'forwarded_from', 'parent_id', 'quoted_message_id', 'body'])]
class Message extends Model implements HasMedia
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids, InteractsWithMedia, SoftDeletes;

    /** The one collection a message has: what was sent along with it. */
    public const ATTACHMENTS = 'attachments';

    /**
     * Where a file hangs.
     *
     * On the message rather than on the channel, because that is what the
     * feature is: a file is always something somebody said, with or without
     * words around it. It also means a deleted message takes its files with it
     * without anything extra having to remember to.
     *
     * Stored on the private disk (see config/media-library.php) and served
     * through a route that asks the ChannelPolicy — a public URL would carry an
     * attachment out of a private channel the moment somebody forwarded it.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS);
    }

    /**
     * A small copy for in the conversation.
     *
     * Only for images: the message list shows dozens of them at a time, and
     * handing over the original of each is what makes a channel full of
     * screenshots slow to open. The original stays reachable for whoever clicks
     * through.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // The conversion's own settings first, the image manipulations after:
        // a manipulation hands back the image driver, so anything chained past
        // it is no longer talking to the conversion.
        $this->addMediaConversion('preview')
            ->nonQueued()
            ->performOnCollections(self::ATTACHMENTS)
            ->width(800)
            ->height(800)
            ->format('webp');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_reply_at' => 'datetime',
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * The member who wrote this, or null when a webhook posted it.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * The webhook that posted this, if it still exists. A revoked or deleted
     * webhook leaves the message intact — see isFromBot().
     *
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    /**
     * The older message this one quotes.
     *
     * withTrashed, because a quote outlives what it quotes: the original may
     * have been deleted since, and the answer still has to say what it was
     * answering — as a tombstone rather than as nothing at all.
     *
     * @return BelongsTo<Message, $this>
     */
    public function quoted(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'quoted_message_id')->withTrashed();
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    /**
     * The members who closed this thread in their own sidebar.
     *
     * @return BelongsToMany<User, $this>
     */
    public function closedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'thread_user')
            ->withPivot('closed_at')
            ->withTimestamps();
    }

    /**
     * Closing is per member and idempotent: closing an already closed thread
     * moves the timestamp forward, which is what makes "closed after the last
     * reply" the thing the sidebar can ask about.
     */
    public function closeFor(User $user): void
    {
        $this->closedBy()->syncWithoutDetaching([
            $user->id => ['closed_at' => now()],
        ]);
    }

    public function reopenFor(User $user): void
    {
        $this->closedBy()->detach($user->id);
    }

    /**
     * Who pinned this to the channel. Null when nobody did, and also when the
     * person who did is gone — see the migration.
     *
     * @return BelongsTo<User, $this>
     */
    public function pinner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }

    /**
     * The pinned messages of a channel, in the order they were pinned.
     *
     * Oldest first, because pins are read as a list rather than as a feed: the
     * channel intro that was put up on day one has to keep its place at the top
     * when a second rule is added later.
     *
     * @param  Builder<$this>  $query
     */
    public function scopePinned(Builder $query): void
    {
        $query->whereNotNull('pinned_at')->orderBy('pinned_at')->orderBy('id');
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    /**
     * Pin this message to its channel.
     *
     * Idempotent by leaving an existing pin alone: pinning something that is
     * already pinned would otherwise move it to the end of the list and rewrite
     * who put it there, which is not what pressing the button twice means.
     */
    public function pin(User $user): void
    {
        if ($this->isPinned()) {
            return;
        }

        $this->forceFill([
            'pinned_at' => now(),
            'pinned_by' => $user->id,
        ])->save();
    }

    public function unpin(): void
    {
        if (! $this->isPinned()) {
            return;
        }

        $this->forceFill([
            'pinned_at' => null,
            'pinned_by' => null,
        ])->save();
    }

    /**
     * Asks bot_name rather than webhook_id on purpose: the name is a snapshot
     * that survives the webhook being deleted, so it is the one field that is
     * always there to answer this.
     */
    public function isFromBot(): bool
    {
        return $this->bot_name !== null;
    }

    public function isThreadParent(): bool
    {
        return $this->parent_id === null && $this->reply_count > 0;
    }

    /**
     * Everything a reader should still see, deleted messages included when they
     * have to stay.
     *
     * A deleted message with replies keeps its row as a tombstone: the replies
     * are other people's words, and the "N antwoorden" link on this row is the
     * only way into that thread. Without replies it is simply gone.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->withTrashed()->where(
            fn (Builder $query) => $query
                ->whereNull('deleted_at')
                ->orWhere('reply_count', '>', 0)
        );
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    /**
     * Full-text search against the generated tsvector column.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, string $terms): void
    {
        $query->whereRaw(
            "search_vector @@ plainto_tsquery('simple', ?)",
            [$terms]
        );
    }

    /**
     * ULIDs sort lexicographically by creation time, so paging on the primary
     * key gives stable cursors even while new messages arrive.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBefore(Builder $query, ?string $cursor): void
    {
        $query->when($cursor, fn (Builder $query) => $query->where('id', '<', $cursor));
    }
}
