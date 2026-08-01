<?php

namespace App\Models;

use App\Enums\WorkspaceRole;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $invited_by
 * @property string $email
 * @property WorkspaceRole $role
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 */
#[Fillable(['workspace_id', 'invited_by', 'email', 'role', 'token', 'expires_at'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    /**
     * How long an invitation stays usable. Long enough to survive a holiday,
     * short enough that a link found in an old mailbox is no longer a way in.
     */
    public const VALID_FOR_DAYS = 14;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => WorkspaceRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * The secret in the mailed link. Holding it is the whole of the proof that
     * you were invited, so it is long and random rather than derived from
     * anything about the invitation.
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
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * The channels this invitation opens up. Empty for anyone joining as a
     * regular member — they can find the workspace's channels themselves.
     *
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class);
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
