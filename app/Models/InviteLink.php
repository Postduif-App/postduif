<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\InviteLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A way into a workspace that anybody holding it can use.
 *
 * The twin of Invitation, for the case where you do not know who you are
 * inviting: a link in a mail you write yourself, in a signature, on a page.
 * Because it names nobody, the limits are what keep it from being a permanent
 * open door — how often it may be used, and until when.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $token
 * @property WorkspaceRole $role
 * @property int|null $max_uses
 * @property Carbon|null $expires_at
 * @property int $uses
 * @property Carbon|null $revoked_at
 */
#[Fillable(['workspace_id', 'created_by', 'token', 'role', 'max_uses', 'expires_at'])]
class InviteLink extends Model
{
    /** @use HasFactory<InviteLinkFactory> */
    use HasFactory;

    /**
     * The token is the whole of the proof that you were let in, so it never
     * travels along in a payload that was not asked for it by name.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'max_uses' => 'integer',
            'uses' => 'integer',
        ];
    }

    /**
     * The secret in the link. Long and random rather than derived from anything
     * about the workspace: it is handed to people who have no account yet, and
     * for them it is the only credential there is.
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The channels this link drops somebody into.
     *
     * Unlike Invitation::channels() this is meaningful for a member too: a link
     * shared with a project group is usually shared to get everybody into the
     * same two channels, not just into the workspace.
     *
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** A link with no date on it never expires — that is what null means here. */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Likewise: no maximum means no ceiling. */
    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    /**
     * Whether it still lets anybody in. All three reasons are kept apart so the
     * landing page can say which one it is — "this link has been used up" and
     * "this link was withdrawn" are different messages to the person holding
     * it.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->hasExpired() && ! $this->isExhausted();
    }

    /**
     * The links still worth showing at the top of the list: not withdrawn, not
     * past their date, not full.
     *
     * Expressed in SQL rather than by filtering isUsable() in PHP so it can be
     * counted and paged; the nulls are spelled out because in SQL a null
     * compares to nothing, and "no limit" has to read as "not reached".
     *
     * @param  Builder<InviteLink>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn (Builder $query) => $query
                ->whereNull('max_uses')
                ->orWhereColumn('uses', '<', 'max_uses'));
    }
}
