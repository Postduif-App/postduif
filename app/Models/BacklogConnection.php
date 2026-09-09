<?php

namespace App\Models;

use Database\Factories\BacklogConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * One workspace's arrangement with one Backlog installation.
 *
 * Two directions of traffic meet on this row. Inbound: Backlog signs a webhook
 * with `webhook_secret` and this connection is how the receiver finds the
 * secret to check it against. Outbound: this server authenticates to Backlog's
 * `/api/v1` with `client_id`/`client_secret` (an OAuth2 client-credentials
 * grant) and caches what it gets back in `access_token`, so a PATCH or a
 * comment does not have to authenticate first.
 *
 * `consecutive_failures` and `isHealthy()` mirror the shape Backlog's own
 * webhook subscriptions use for the same problem the other way round: an
 * endpoint that has gone quietly wrong should stop being hammered rather than
 * being retried forever, and a beheerder should be told rather than left to
 * notice a silence.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property string $backlog_url
 * @property string $client_id
 * @property string|null $client_secret
 * @property string|null $access_token
 * @property Carbon|null $access_token_expires_at
 * @property string|null $webhook_secret
 * @property list<string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'channel_id', 'backlog_url', 'client_id', 'events'])]
class BacklogConnection extends Model
{
    /** @use HasFactory<BacklogConnectionFactory> */
    use HasFactory;

    public const EVENT_ISSUE_CREATED = 'issue.created';

    public const EVENT_ISSUE_UPDATED = 'issue.updated';

    public const EVENT_ISSUE_DELETED = 'issue.deleted';

    public const EVENT_ISSUE_COMMENTED = 'issue.commented';

    public const EVENT_PROJECT_UPDATE_PUBLISHED = 'project_update.published';

    /**
     * Every event Backlog can deliver, in the order a screen should offer them.
     *
     * @var list<string>
     */
    public const EVENTS = [
        self::EVENT_ISSUE_CREATED,
        self::EVENT_ISSUE_UPDATED,
        self::EVENT_ISSUE_DELETED,
        self::EVENT_ISSUE_COMMENTED,
        self::EVENT_PROJECT_UPDATE_PUBLISHED,
    ];

    /**
     * How many deliveries in a row may fail before this connection turns
     * itself off.
     *
     * Ten, the same order of magnitude ContractWebhook's retries reach over a
     * single delivery, but counted across deliveries instead: ten separate
     * failures is not a receiver having a bad minute, it is a receiver that is
     * gone, and continuing to call it just to record another failure teaches
     * nobody anything they do not already know from the ninth.
     */
    public const FAILURE_LIMIT = 10;

    /**
     * None of these may leave through a serialisation — see ApiToken::$hidden
     * for the same rule and the same reason.
     *
     * @var list<string>
     */
    protected $hidden = ['client_secret', 'access_token', 'webhook_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The channel a synced ticket is created in.
     *
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * Whether this connection asked to hear about this kind of news.
     *
     * The same question ContractWebhook::wants() asks, over the same shape of
     * column, for the same reason: the event name is exactly the value the
     * webhook payload's own `event` field carries, so there is nothing in
     * between to translate wrongly.
     */
    public function wants(string $event): bool
    {
        return in_array($event, $this->events, true);
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isHealthy(): bool
    {
        return $this->consecutive_failures < self::FAILURE_LIMIT;
    }

    /**
     * A delivery to or from Backlog failed. Past the limit, this connection
     * turns itself off rather than going on being called (or calling out)
     * into what has become silence — see FAILURE_LIMIT.
     */
    public function recordFailure(): void
    {
        $this->forceFill(['consecutive_failures' => $this->consecutive_failures + 1]);

        if (! $this->isHealthy()) {
            $this->forceFill(['is_active' => false]);
        }

        $this->save();
    }

    /**
     * A delivery to or from Backlog succeeded. Resets the streak, so a
     * connection that failed nine times in a row and then answered gets to
     * fail nine more before it is switched off — a receiver that comes back is
     * not still the receiver that just went quiet.
     */
    public function recordSuccess(): void
    {
        $this->forceFill(['consecutive_failures' => 0])->save();
    }

    /**
     * A bearer token to call Backlog's `/api/v1` with.
     *
     * Cached on the row until shortly before it expires, so most calls cost no
     * authentication round trip at all. Refreshed a minute early rather than
     * exactly on expiry, so a token that is about to die between the check here
     * and the request that uses it is never handed out.
     *
     * Null when Backlog refused the credentials — there is nothing this call
     * can do about that beyond saying so; the caller decides how to record it.
     */
    public function accessToken(): ?string
    {
        if ($this->access_token !== null
            && $this->access_token_expires_at !== null
            && $this->access_token_expires_at->subMinute()->isFuture()) {
            return $this->access_token;
        }

        $response = Http::asForm()->post(rtrim($this->backlog_url, '/').'/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 3600);

        $this->forceFill([
            'access_token' => $token,
            'access_token_expires_at' => now()->addSeconds($expiresIn),
        ])->save();

        return $token;
    }
}
