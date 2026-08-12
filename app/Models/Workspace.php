<?php

namespace App\Models;

use App\Enums\AttachmentType;
use App\Enums\MemberPanelVisibility;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use App\Features\WorkspaceFeature;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $avatar_path
 * @property MemberPanelVisibility $member_panel
 * @property array<int, string> $blocked_words
 * @property bool $uploads_enabled
 * @property array<int, string> $allowed_attachment_types
 * @property int $max_attachment_kb
 * @property int $max_transfer_kb
 * @property int $max_transfer_days
 * @property bool $link_previews_enabled
 * @property WorkspaceAccent $accent
 * @property WorkspaceFont $font
 * @property int $next_ticket_number
 * @property int $next_document_number
 * @property int $owner_id
 * @property-read WorkspaceMembership $membership The membership this workspace
 *     was loaded through, on User::workspaces(). Absent otherwise.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'owner_id', 'blocked_words', 'accent', 'font', 'member_panel', 'uploads_enabled', 'allowed_attachment_types', 'max_attachment_kb', 'max_transfer_kb', 'max_transfer_days', 'link_previews_enabled'])]
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
        'member_panel' => MemberPanelVisibility::Off->value,
        'blocked_words' => '[]',
        'uploads_enabled' => true,
        'link_previews_enabled' => false,
        'max_attachment_kb' => 10240,
        'max_transfer_kb' => 2097152,
        'max_transfer_days' => 14,
        'accent' => WorkspaceAccent::Neutral->value,
        'font' => WorkspaceFont::InstrumentSans->value,
    ];

    /**
     * Addresses directly under /app that are not a workspace.
     *
     * The slug is a wildcard one segment under /app, so anything else living
     * there has to be kept out of it twice: the route pattern refuses these
     * outright, and CreateWorkspace will not hand one out. Either alone leaves
     * a hole — a workspace that claimed "settings" would be unreachable, and a
     * pattern that forgot one would swallow the page.
     *
     * @var list<string>
     */
    public const RESERVED_SLUGS = ['settings', 'nieuw'];

    /**
     * A new workspace gets its roles before anybody can join it.
     *
     * created rather than creating: the rows point back at a workspace that
     * has to exist first.
     *
     * The first channel is deliberately not here, though it is just as true of
     * every workspace somebody makes. Roles are structural — without them no
     * permission resolves and the workspace is broken — so they belong to the
     * row itself. A channel is something in the workspace rather than part of
     * it, and hanging it off this event would hand every test fixture a
     * conversation it never asked for. See CreateHomeChannel for who calls it.
     */
    protected static function booted(): void
    {
        static::created(fn (self $workspace) => $workspace->seedSystemRoles());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'member_panel' => MemberPanelVisibility::class,
            'blocked_words' => 'array',
            'uploads_enabled' => 'boolean',
            'allowed_attachment_types' => 'array',
            'max_attachment_kb' => 'integer',
            'max_transfer_kb' => 'integer',
            'max_transfer_days' => 'integer',
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

    /**
     * Whether a part of the product is switched on here.
     *
     * The scope is passed explicitly rather than resolved from the current
     * request: a scheduled message goes out from a queue worker, where there
     * is no request to read a workspace from, and a flag that silently answers
     * for the wrong workspace is worse than one that is a nuisance to ask.
     *
     * @param  class-string<WorkspaceFeature>  $feature
     */
    public function hasFeature(string $feature): bool
    {
        return Feature::for($this)->active($feature);
    }

    /**
     * Every feature and its stand here, for a screen that shows them all.
     *
     * @return array<class-string<WorkspaceFeature>, bool>
     */
    public function featureStates(): array
    {
        $values = Feature::for($this)->values(WorkspaceFeature::ALL);

        /** @var array<class-string<WorkspaceFeature>, bool> */
        return array_map(fn ($value): bool => $value === true, $values);
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
            ->withPivot(['workspace_role_id', 'display_name', 'joined_at'])
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

    /** @return HasMany<Workflow, $this> */
    public function workflows(): HasMany
    {
        return $this->hasMany(Workflow::class);
    }

    /**
     * The questionnaires this workspace has written.
     *
     * On the workspace rather than on a channel, unlike a poll: the same form
     * may hang in three channels and behind a link at the same time, and none
     * of those is where it lives.
     *
     * @return HasMany<Form, $this>
     */
    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    /**
     * The pictures this workspace made up for itself, by name.
     *
     * Ordered here rather than at each of the three places that read them — the
     * settings screen, the picker and the chat shell — because a list of emoji
     * that comes back in a different order per screen is one nobody can point
     * at.
     *
     * @return HasMany<CustomEmoji, $this>
     */
    public function customEmoji(): HasMany
    {
        return $this->hasMany(CustomEmoji::class)->orderBy('name');
    }

    /**
     * The hours worked here, newest first.
     *
     * Against the workspace and not only against the member, because that is
     * whose account it is: somebody who leaves takes their messages with them
     * in the sense that they stay where they were written, and their hours are
     * the same kind of record.
     *
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->latest('started_at');
    }

    /**
     * Where this workspace's mail leaves from, if it said.
     *
     * Nullable on purpose and nowhere given a default row: no settings and
     * settings set to Default mean the same thing, and creating a row for every
     * workspace up front would only add a second way to say it.
     *
     * @return HasOne<WorkspaceMailSettings, $this>
     */
    public function mailSettings(): HasOne
    {
        return $this->hasOne(WorkspaceMailSettings::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * The prikbord.
     *
     * Here so the routes can scope a notice to its workspace by binding rather
     * than by a check every method has to remember — a post id from another
     * workspace is then a 404 before any controller runs.
     *
     * @return HasMany<BoardPost, $this>
     */
    public function boardPosts(): HasMany
    {
        return $this->hasMany(BoardPost::class);
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

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
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
     * Hand out the next document number and move the counter along.
     *
     * Everything the ticket counter above says applies here word for word: the
     * lock is what keeps two simultaneous creates from reading the same number,
     * and this belongs inside the transaction that writes the document so an
     * abandoned insert does not take a number with it.
     */
    public function claimDocumentNumber(): int
    {
        return DB::transaction(function (): int {
            $locked = static::query()->whereKey($this->id)->lockForUpdate()->firstOrFail();

            $number = $locked->next_document_number;

            $locked->forceFill(['next_document_number' => $number + 1])->save();
            $this->next_document_number = $number + 1;

            return $number;
        });
    }

    /**
     * The roles this workspace has, its own and the four it started with.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class)->inOrder();
    }

    /**
     * The people here from outside, and the people here from inside.
     *
     * Asked of the role's is_external flag rather than of a role named "guest".
     * A workspace writes its own roles now, so "from outside" is a property a
     * role carries — and a workspace that called theirs "Leverancier" would
     * otherwise have suppliers counted as colleagues everywhere this is used.
     *
     * @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'>
     */
    public function externalMembers(): BelongsToMany
    {
        return $this->membersByExternality(true);
    }

    /** @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'> */
    public function internalMembers(): BelongsToMany
    {
        return $this->membersByExternality(false);
    }

    /** @return BelongsToMany<User, $this, WorkspaceMembership, 'membership'> */
    private function membersByExternality(bool $external): BelongsToMany
    {
        return $this->members()->whereIn(
            'workspace_user.workspace_role_id',
            Role::query()
                ->where('workspace_id', $this->id)
                ->where('is_external', $external)
                ->select('id'),
        );
    }

    /**
     * Give a workspace the four roles it starts life with.
     *
     * On the model rather than in whichever screen makes a workspace, because
     * it is an invariant and not a step: a workspace with no roles is one
     * nobody can be a member of, and there is more than one way in here — the
     * admin panel, a factory, and whatever comes next.
     */
    public function seedSystemRoles(): void
    {
        foreach (SystemRole::cases() as $position => $role) {
            $this->roles()->firstOrCreate(['key' => $role->value], [
                'name' => $role->getLabel(),
                'is_external' => $role->isExternal(),
                'is_system' => true,
                'position' => $position,
                'abilities' => array_map(
                    fn (WorkspaceAbility $ability): string => $ability->value,
                    $role->defaultAbilities(),
                ),
            ]);
        }
    }

    /**
     * What somebody is here, or null when they do not belong.
     *
     * Remembered for the length of the request. Every policy asks this, several
     * ask it more than once for the same person, and it used to be a query each
     * time — cheap when the answer was one column, less so now that it is a row
     * with a bag of rights on it.
     *
     * @var array<int, Role|null>
     */
    private array $rolesByUser = [];

    public function roleFor(User $user): ?Role
    {
        return $this->rolesByUser[$user->id] ??= $this->roles()
            ->whereHas('holders', fn (Builder $holders) => $holders->whereKey($user->id))
            ->first();
    }

    /**
     * Whether somebody here may do a particular thing.
     *
     * The question every policy actually has. Through the role rather than
     * against it: what a role may do is a row a workspace edits, and a policy
     * that compared against a name would go on being right about the four this
     * application ships with and wrong about every other.
     */
    public function allows(User $user, WorkspaceAbility $ability): bool
    {
        return $this->roleFor($user)?->allows($ability) ?? false;
    }

    /**
     * Whether somebody here is from outside.
     *
     * Its own question rather than one more ability, and the answer nearly
     * every visibility check starts with — see scopeBrowsableBy for the same
     * question in the form a query can ask it.
     */
    public function isExternal(User $user): bool
    {
        return $this->roleFor($user)->is_external ?? true;
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
     * SystemRole::canBrowseWorkspace(), for the places that filter a list
     * instead of judging a single record.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeBrowsableBy(Builder $query, User $user): void
    {
        $query->whereHas('members', fn (Builder $members) => $members
            ->whereKey($user->id)
            /*
             * Joined rather than asked of a list of role names. Which roles are
             * external is a column now, so the query reads the same fact the
             * policies do — a list of values here would be a second answer to
             * the same question, and the day the two disagree is the day a
             * guest sees every public channel.
             */
            ->join('workspace_roles', 'workspace_roles.id', '=', 'workspace_user.workspace_role_id')
            ->where('workspace_roles.is_external', false));
    }
}
