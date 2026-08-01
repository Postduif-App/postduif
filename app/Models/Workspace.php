<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Enums\BroadcastMentionPolicy;
use App\Enums\ChannelCreationPolicy;
use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use App\Enums\WorkspaceRole;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $avatar_path
 * @property BroadcastMentionPolicy $broadcast_mentions
 * @property ChannelCreationPolicy $channel_creation
 * @property array<int, string> $blocked_words
 * @property bool $uploads_enabled
 * @property array<int, string> $allowed_attachment_types
 * @property int $max_attachment_kb
 * @property bool $link_previews_enabled
 * @property WorkspaceAccent $accent
 * @property WorkspaceFont $font
 * @property int $next_ticket_number
 * @property int $owner_id
 * @property-read WorkspaceMembership $membership The membership this workspace
 *     was loaded through, on User::workspaces(). Absent otherwise.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'owner_id', 'broadcast_mentions', 'blocked_words', 'accent', 'font', 'channel_creation', 'uploads_enabled', 'allowed_attachment_types', 'max_attachment_kb', 'link_previews_enabled'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * The database default only applies on insert, so a model that was just
     * created still reads null for it. Declaring it here means every workspace
     * has an answer from the moment it exists, saved or not.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'broadcast_mentions' => BroadcastMentionPolicy::Admins->value,
        'channel_creation' => ChannelCreationPolicy::Everyone->value,
        'blocked_words' => '[]',
        'uploads_enabled' => true,
        'link_previews_enabled' => false,
        'max_attachment_kb' => 10240,
        'accent' => WorkspaceAccent::Neutral->value,
        'font' => WorkspaceFont::InstrumentSans->value,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'broadcast_mentions' => BroadcastMentionPolicy::class,
            'channel_creation' => ChannelCreationPolicy::class,
            'blocked_words' => 'array',
            'uploads_enabled' => 'boolean',
            'allowed_attachment_types' => 'array',
            'max_attachment_kb' => 'integer',
            'link_previews_enabled' => 'boolean',
            'accent' => WorkspaceAccent::class,
            'font' => WorkspaceFont::class,
        ];
    }

    /**
     * The kinds of file this workspace takes.
     *
     * Falls back to the defaults rather than sitting in $attributes, which can
     * only hold a literal — and a literal here would be a second copy of the
     * list in AttachmentType, free to drift from it. Unknown values are dropped
     * on the way out, so a group that is removed from the enum stops counting
     * without anything having to go and clean up rows.
     *
     * @return array<int, AttachmentType>
     */
    public function allowedAttachmentTypes(): array
    {
        $stored = $this->allowed_attachment_types ?? AttachmentType::defaults();

        return array_values(array_filter(array_map(
            fn (string $value): ?AttachmentType => AttachmentType::tryFrom($value),
            $stored,
        )));
    }

    /**
     * Whether a file of this type may be sent here at all.
     *
     * The one place that answers it, so the composer, the endpoint and anything
     * later cannot disagree. Uploads being switched off wins over every group:
     * off means off, not "off unless it happens to be an image".
     */
    public function acceptsAttachment(string $mimeType): bool
    {
        if (! $this->uploads_enabled) {
            return false;
        }

        foreach ($this->allowedAttachmentTypes() as $type) {
            if ($type->accepts($mimeType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where this workspace's logo can be fetched, or null when it has none.
     *
     * Stored as a column and a file rather than through the media library —
     * see the users migration for the type clash that rules that out.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : route('avatars.workspace', $this);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            /*
             * Named rather than left as "pivot". A User can carry either kind
             * of membership depending on which relation loaded it, and one
             * property that means two different rows is a property nothing can
             * describe. This one always means the workspace side.
             */
            ->as('membership')
            ->withPivot(['role', 'display_name', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Every tag in this workspace, whether or not it is on a channel.
     *
     * @return HasMany<ChannelTag, $this>
     */
    public function channelTags(): HasMany
    {
        return $this->hasMany(ChannelTag::class)->inOrder();
    }

    /** @return HasMany<Channel, $this> */
    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * The shareable ways in, as opposed to the invitations above, which each
     * name one address.
     *
     * @return HasMany<InviteLink, $this>
     */
    public function inviteLinks(): HasMany
    {
        return $this->hasMany(InviteLink::class);
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * Hand out the next ticket number and move the counter along.
     *
     * The lock is the whole point. Two people opening a ticket at the same
     * moment would otherwise both read the same counter and both try to use it;
     * the unique index would catch the second one, but as a failed insert rather
     * than as the next number. Locking the workspace row makes numbering within
     * one workspace happen one at a time, which is exactly as fast as it needs
     * to be for something people do a handful of times an hour.
     *
     * Call this inside the transaction that writes the ticket, so a ticket that
     * never gets stored does not take a number with it.
     */
    public function claimTicketNumber(): int
    {
        return DB::transaction(function (): int {
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            $number = $locked->next_ticket_number;

            $locked->forceFill(['next_ticket_number' => $number + 1])->save();
            $this->next_ticket_number = $number + 1;

            return $number;
        });
    }

    /**
     * Resolve a member's role, or null when the user does not belong here.
     */
    public function roleFor(User $user): ?WorkspaceRole
    {
        // One column rather than the whole membership: this is asked on nearly
        // every request, and the answer is a single string.
        $role = $this->members()->whereKey($user->id)->value('workspace_user.role');

        return $role === null ? null : WorkspaceRole::from($role);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Whether these two already sit in a channel together.
     *
     * This is what a guest's world is made of: they cannot browse the
     * workspace, so the people in their own channels are the only ones they are
     * supposed to know exist. Asked before letting a guest open a conversation,
     * so the DM picker cannot become the members list they were kept out of.
     */
    public function hasSharedChannel(User $user, User $other): bool
    {
        return $this->channels()
            ->whereHas('members', fn (Builder $members) => $members->whereKey($user->id))
            ->whereHas('members', fn (Builder $members) => $members->whereKey($other->id))
            ->exists();
    }

    /**
     * Workspaces the user may look around in — so, the ones they belong to in
     * a role that is not a guest. The query-side twin of
     * WorkspaceRole::canBrowseWorkspace(), for the places that filter a list
     * instead of judging a single record.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBrowsableBy(Builder $query, User $user): void
    {
        $query->whereHas('members', fn (Builder $members) => $members
            ->whereKey($user->id)
            ->whereIn('workspace_user.role', WorkspaceRole::browsingValues()));
    }
}
