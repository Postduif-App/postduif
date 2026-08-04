<?php

namespace App\Models;

use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A token that lets an AI client act as one member.
 *
 * The same shape as a webhook token, and deliberately so — but where a webhook
 * posts as a bot into one channel, this acts as a person across everything that
 * person may see. That is a much larger key, which is why it is named, dated,
 * and revocable from the member's own settings.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $token_hash
 * @property string|null $token
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'name'])]
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    /** Prefixed so a token that turns up somewhere is recognisable for what it is. */
    private const PREFIX = 'mcp_';

    private const RANDOM_LENGTH = 48;

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
