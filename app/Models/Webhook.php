<?php

namespace App\Models;

use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property string $name
 * @property string $bot_name
 * @property string|null $body_path
 * @property string $token_hash
 * @property string|null $token
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['workspace_id', 'channel_id', 'name', 'bot_name', 'body_path', 'created_by'])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    /**
     * Long enough that guessing is hopeless, and prefixed so a token that ends
     * up somewhere it should not be is recognisable for what it is.
     */
    private const PREFIX = 'whk_';

    private const RANDOM_LENGTH = 48;

    /**
     * Neither of these may reach a payload by accident. The token is the whole
     * credential, and the hash is the only thing standing between a copied
     * database row and posting rights. Showing the URL is deliberate and goes
     * through url(), never through a model serialisation.
     *
     * @var list<string>
     */
    protected $hidden = ['token_hash', 'token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Encrypted rather than plain, so the column is unreadable without
            // the APP_KEY. That is weaker than storing nothing at all, which is
            // what this used to do — see the migration for why it changed.
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Mint a token, store it, and hand back the plain value.
     *
     * Both forms are kept: the hash is what the endpoint looks up, the
     * encrypted copy is what lets somebody see their own URL again later.
     * Regenerating replaces both, so the previous URL stops working the moment
     * a new one is handed out.
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
     * The full posting URL, or null when this webhook predates the stored
     * token and there is nothing to reconstruct it from.
     *
     * A revoked webhook has no working URL, and showing the dead one would
     * invite somebody to paste it into an integration that then silently never
     * posts. Regenerating is the way back.
     */
    public function url(): ?string
    {
        if ($this->token === null || $this->isRevoked()) {
            return null;
        }

        return route('webhooks.messages.store', $this->token);
    }

    /**
     * A plain SHA-256, deliberately not a password hash.
     *
     * The endpoint has to find a webhook by its token, which means looking the
     * hash up in an index — so it must be deterministic and unsalted. That is
     * safe here in a way it never is for passwords: the token is 48 random
     * characters, so there is no dictionary to run through.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
