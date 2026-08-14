<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Availability;
use App\Features\AiAccess;
use App\Http\Middleware\HandleLocale;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property string $timezone
 * @property string|null $locale
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $admin_at
 * @property Carbon|null $suspended_at
 * @property string|null $status_emoji
 * @property string|null $status_text
 * @property Availability $availability
 * @property int|null $status_rule_id
 * @property bool $status_is_manual
 * @property bool $clock_sets_status
 * @property array<int, array{emoji: string|null, text: string}> $recent_statuses
 * @property int|null $notify_after_minutes
 * @property bool $notify_via_mail
 * @property bool $notify_via_pushover
 * @property bool $notify_via_push
 * @property string|null $pushover_user_key
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $avatar_path
 * @property string|null $remember_token
 * @property-read WorkspaceMembership $membership The workspace membership this
 *     user was loaded through, on the relations that name it — see
 *     Workspace::members(). Absent on a user fetched any other way.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'timezone', 'locale', 'bio', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'pushover_user_key'])]
class User extends Authenticatable implements FilamentUser, HasLocalePreference, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Where this person's face is stored, or null when they set none.
     *
     * A column and a file rather than the media library the attachments use,
     * and the reason is a type clash worth writing down: that table keys
     * model_id as a ULID because messages are ULID-keyed, and Postgres will not
     * compare a varchar to a user's integer id. One file with no collection
     * semantics does not need a library anyway.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : route('avatars.user', $this);
    }

    /**
     * The face, under the name the frontend already draws.
     *
     * UserInfo has read `user.avatar` since the starter kit; nothing ever
     * filled it. Filling it here is less code than a second field, and it makes
     * the user menu show a photo without touching the component.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'avatar' => $this->avatarUrl(),
        ];
    }

    /**
     * Handles that address a whole group. Someone called "Here" must not end up
     * owning the "here" handle, or a message meant for the room would quietly
     * reach one person instead. Enforced both when a handle is generated during
     * sign-up and when a moderator edits one.
     *
     * @var array<int, string>
     */
    public const RESERVED_HANDLES = ['here', 'everyone', 'channel', 'all'];

    /**
     * Where somebody is until they say otherwise.
     *
     * The application stores moments in UTC and always will; this is the other
     * question — which wall clock a repeating time means. A default is
     * unavoidable, so it may as well be the one that is right for almost
     * everybody here rather than one that is right for nobody.
     */
    public const DEFAULT_TIMEZONE = 'Europe/Amsterdam';

    /**
     * The database defaults only apply on insert, so a model that was just made
     * still reads null for them. Declared here too, so every user has an
     * availability from the moment they exist.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
        'availability' => Availability::Available->value,
        'recent_statuses' => '[]',
        'clock_sets_status' => false,
        // Off until a browser has actually been asked and said yes, which
        // cannot have happened to an account that was made a moment ago.
        'notify_via_push' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'admin_at' => 'datetime',
            'suspended_at' => 'datetime',
            'availability' => Availability::class,
            'recent_statuses' => 'array',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'status_is_manual' => 'boolean',
            'clock_sets_status' => 'boolean',
            'notify_via_mail' => 'boolean',
            'notify_via_pushover' => 'boolean',
            'notify_via_push' => 'boolean',
            // A credential for somebody's own device. Encrypted rather than
            // hashed: unlike a password it has to be sent onwards to Pushover,
            // so it must be readable — just not by reading the table.
            'pushover_user_key' => 'encrypted',
        ];
    }

    /**
     * The language anything sent to this member should be written in.
     *
     * The name is Laravel's: implementing HasLocalePreference makes the
     * notification sender and the mailer switch to this locale for the whole of
     * building the message, per recipient.
     *
     * That is not a nicety here but the only correct answer. A summary is
     * assembled by a scheduled command with no request behind it, and a
     * notification caused by somebody else is built while the application is
     * still in *their* language. App::getLocale() at that moment is the sender's
     * choice, or the last reader's, or the default — never reliably the
     * recipient's.
     *
     * Null when nothing was chosen, or when what was chosen is no longer a
     * language this application has: null lets Laravel leave the locale alone
     * rather than switching to something that would fall back key by key.
     */
    public function preferredLocale(): ?string
    {
        return in_array($this->locale, HandleLocale::SUPPORTED, true)
            ? $this->locale
            : null;
    }

    /**
     * Where a Pushover notification for this member should go.
     *
     * Named the way Laravel looks it up: routeNotificationFor{Channel}. Returns
     * null when pushes are switched off, so the channel has nothing to send to
     * rather than having to ask about the preference itself.
     */
    public function routeNotificationForPushover(): ?string
    {
        return $this->wantsPushover() ? $this->pushover_user_key : null;
    }

    /**
     * Whether this member has asked to hear about channels they have been away
     * from, and has somewhere for it to arrive.
     *
     * All three halves are the question: a threshold with neither delivery
     * method switched on is a setting that would quietly produce nothing, and
     * "niet storen" is the member saying so out loud right now — which outranks
     * a preference they set months ago.
     *
     * Nothing is written off while they are unavailable: the pointer that
     * records what somebody has been told only moves when a summary actually
     * goes out. So what happened during "niet storen" is still waiting when
     * they come back, rather than having been silently marked as delivered.
     */
    public function wantsAbsenceNotifications(): bool
    {
        if (! $this->availability->allowsNotifications()) {
            return false;
        }

        if ($this->notify_after_minutes === null) {
            return false;
        }

        return $this->notify_via_mail || $this->wantsPushover() || $this->wantsWebPush();
    }

    /**
     * Pushover needs a key for the device it is going to. Asking for pushes
     * without one is not an error worth refusing a form over — it simply is not
     * a delivery method yet.
     */
    public function wantsPushover(): bool
    {
        return $this->notify_via_pushover && filled($this->pushover_user_key);
    }

    /**
     * Whether the browser itself may interrupt this member.
     *
     * Both halves, for the same reason Pushover needs a key: the flag is the
     * wish and a subscription is the only thing that can carry it out. Somebody
     * who allowed notifications on a laptop they have since wiped has the flag
     * on and nowhere for a push to land, and asking only the flag would have
     * the sender build a message for nobody.
     *
     * Deliberately not scoped to a workspace. Which workspaces are worth being
     * interrupted about is a separate question, answered where a notification
     * is sent; this one is only whether the member wants pushes at all.
     */
    public function wantsWebPush(): bool
    {
        return $this->notify_via_push && $this->pushSubscriptions()->exists();
    }

    /**
     * Where a web push for this member should go.
     *
     * Named the way Laravel looks it up: routeNotificationFor{Channel}. Hands
     * back every browser rather than one, because that is what the channel has
     * to send to — a member reading Postduif on a phone and a laptop has two,
     * and telling only one of them is telling half of them.
     *
     * Empty when pushes are switched off, so the channel has nothing to send to
     * rather than having to ask about the preference itself.
     *
     * @return EloquentCollection<int, PushSubscription>
     */
    public function routeNotificationForWebPush(): EloquentCollection
    {
        if (! $this->notify_via_push) {
            return new EloquentCollection;
        }

        return $this->pushSubscriptions()->get();
    }

    /**
     * A platform moderator, as opposed to an owner or admin of a single
     * workspace. Only these users get into the Filament panel.
     */
    public function isAdmin(): bool
    {
        return $this->admin_at !== null;
    }

    /**
     * Barred from the platform by a moderator. Their account, messages and
     * memberships stay intact — only the door is closed. EnsureAccountIsNotSuspended
     * turns this into an actual lock-out, on every request and not just at login.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * A suspended moderator loses the panel too, which is what keeps them from
     * simply lifting their own suspension from the inside.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin() && ! $this->isSuspended();
    }

    /** @return BelongsToMany<Workspace, $this, WorkspaceMembership, 'membership'> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')
            ->using(WorkspaceMembership::class)
            // See Workspace::members() for why this is named.
            ->as('membership')
            ->withPivot(['workspace_role_id', 'display_name', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Whether this member is in a particular workspace, by id.
     *
     * The mirror of Workspace::hasMember(), and here rather than only there
     * because the caller that needed it has a workspace id and no workspace:
     * a shared channel asks "is this person on the other side of the
     * arrangement", and loading a whole workspace to answer yes or no would be
     * a row fetched to be thrown away.
     *
     * Answered from the loaded relation when there is one, which is the case
     * the sidebar hits — the alternative is a query for every channel drawn.
     */
    public function belongsToWorkspace(int $workspaceId): bool
    {
        if ($this->relationLoaded('workspaces')) {
            return $this->workspaces->contains('id', $workspaceId);
        }

        return $this->workspaces()->whereKey($workspaceId)->exists();
    }

    /**
     * The workspaces this member belongs to that let AI clients in.
     *
     * One definition for all of the MCP tools, deliberately. Each of them
     * queries differently — channels by name, messages by term, a single
     * channel by id — so there is no single query to put the check in, and
     * three copies of "and the workspace allows it" is three chances to write
     * a fourth tool without one.
     *
     * @return Collection<int, Workspace>
     */
    public function workspacesOpenToAi(): Collection
    {
        return $this->workspaces
            ->filter(fn (Workspace $workspace): bool => $workspace->hasFeature(AiAccess::class))
            ->values();
    }

    /**
     * What time it is where this member is.
     *
     * Every repeating rule is read against this rather than against the
     * application's UTC. A rule saying nine o'clock means the nine on their own
     * clock, and nothing about it converts.
     */
    public function localNow(?Carbon $at = null): Carbon
    {
        return ($at?->copy() ?? Carbon::now())->setTimezone($this->timezone);
    }

    /**
     * The rule in force right now, or null when none covers this moment.
     *
     * First match wins, which is why order is a field and not a preference:
     * "and this the rest of the time" is written as a rule covering everything,
     * placed underneath the one that does not.
     */
    public function activeStatusRule(?Carbon $at = null): ?StatusRule
    {
        $localNow = $this->localNow($at);

        return $this->statusRules
            ->first(fn (StatusRule $rule): bool => $rule->matchesAt($localNow));
    }

    /**
     * This member's rules, in the order they are asked.
     *
     * @return HasMany<StatusRule, $this>
     */
    public function statusRules(): HasMany
    {
        return $this->hasMany(StatusRule::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The hours this member clocked, across every workspace they work for.
     *
     * Every workspace, because the relation cannot sensibly be anything else —
     * a member has one set of rows and the workspace is a column on them. Every
     * screen that shows hours scopes to one workspace itself, and the shift
     * that is running is asked for by openShiftIn() rather than found in here.
     *
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class)->latest('started_at');
    }

    /**
     * The shift this member has running in a workspace, if any.
     *
     * At most one — the database says so with a partial unique index, so this
     * may hand back a single row without wondering which of several it means.
     */
    public function openShiftIn(Workspace $workspace): ?TimeEntry
    {
        return $this->timeEntries()
            ->where('workspace_id', $workspace->id)
            ->running()
            ->first();
    }

    /** @return BelongsToMany<Channel, $this, ChannelMembership, 'pivot'> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class)
            ->using(ChannelMembership::class)
            ->withPivot(['last_read_message_id', 'last_read_at', 'last_notified_message_id', 'muted_at', 'muted_until', 'favorited_at', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * The browsers this member has agreed to be interrupted on.
     *
     * Newest first, because the settings screen lists them and the one somebody
     * has just allowed is the one they are looking for.
     *
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class)->latest('id');
    }

    /**
     * Threads this member closed in the sidebar, by their parent message.
     *
     * @return BelongsToMany<Message, $this>
     */
    public function closedThreads(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'thread_user')
            ->withPivot('closed_at')
            ->withTimestamps();
    }
}
