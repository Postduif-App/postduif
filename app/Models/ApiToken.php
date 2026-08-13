<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A token that lets something outside the app act as one member.
 *
 * It was built for MCP clients and now opens the plain HTTP API as well, which
 * is what the name says and the old one (McpToken) did not.
 *
 * The same shape as a webhook token, and deliberately so — but where a webhook
 * posts as a bot into one channel, this acts as a person across everything that
 * person may see. That is a much larger key, which is why it is named, dated,
 * and revocable from the member's own settings.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string $name
 * @property list<string>|null $scopes
 * @property string $token_hash
 * @property string|null $token
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'workspace_id', 'name', 'scopes'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /** Prefixed so a token that turns up somewhere is recognisable for what it is. */
    private const PREFIX = 'mcp_';

    private const RANDOM_LENGTH = 48;

    /**
     * Reaching the contracts of a workspace: reading them, and putting new ones
     * in front of people to sign.
     *
     * A named scope rather than a general one because of what is behind it. The
     * endpoints this opens send mail to people outside the application asking
     * for a signature, and that is not something a token minted to set somebody
     * status should be able to do by having been made first.
     */
    public const SCOPE_CONTRACTS = 'contracts';

    /**
     * Every scope there is, in the order a screen should offer them.
     *
     * Written here rather than in an enum: the set is a fixed list of strings
     * the application decides, it is read by a middleware alias
     * (`api.scope:contracts`) that can only carry a string anyway, and one
     * member of a would-be enum is a type nobody can be wrong about.
     *
     * @var list<string>
     */
    public const SCOPES = [self::SCOPE_CONTRACTS];

    /**
     * Neither may reach a payload by accident: the token is the whole
     * credential and the hash is what stands between a copied row and somebody
     * else's account. Showing it back is deliberate and goes through plain(),
     * never through a serialisation.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Unreadable without the APP_KEY. Weaker than storing nothing, and
            // chosen for the same reason the webhooks made that trade: a token
            // you cannot see again is a token you lose the moment you close
            // the tab, and this one is meant to be pasted into a config file.
            'token' => 'encrypted',
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The one workspace this token is for, or null for all of them.
     *
     * Null is the older and wider of the two: it means the token speaks for its
     * member wherever that member is, which is what every token minted before
     * this column existed does and what the status, channels and messages
     * endpoints are written against.
     *
     * A workspace here does not by itself grant anything — the member still
     * has to be in it, which AuthenticateApiToken checks on every request
     * rather than trusting the row. Somebody who has left keeps the token in
     * their config file; what they lose is what it opens.
     *
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whether this token was granted a particular scope.
     *
     * A null scopes column is not "all of them". It means the token predates
     * scopes, or was made without asking for any, and both should reach exactly
     * what they reached before — which is every endpoint that has never asked
     * this question, and none of the ones that do.
     *
     * Written the other way round it would read better and be wrong: a token
     * from last month would inherit whatever scope is invented next, including
     * the ones that send mail out of the building.
     */
    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], strict: true);
    }

    /**
     * Mint a token, store both forms, and hand back the plain value.
     */
    public function regenerateToken(): string
    {
        $token = self::PREFIX.Str::random(self::RANDOM_LENGTH);

        $this->forceFill([
            'token_hash' => self::hashToken($token),
            'token' => $token,
            'revoked_at' => null,
        ]);

        return $token;
    }

    /**
     * The token as it can be pasted, or null once it is revoked.
     *
     * A revoked token is shown as gone rather than as a dead string: pasting
     * one into a client that then silently never connects is worse than seeing
     * nothing.
     */
    public function plain(): ?string
    {
        return $this->isRevoked() ? null : $this->token;
    }

    /**
     * A plain SHA-256, deliberately not a password hash.
     *
     * The middleware finds a token by looking its hash up in an index, so it
     * has to be deterministic and unsalted. Safe here in a way it never is for
     * passwords: 48 random characters leave no dictionary to run through.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
