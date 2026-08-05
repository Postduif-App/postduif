<?php

namespace App\Models;

use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Something the workspace does by itself: one trigger, then a row of steps.
 *
 * The trigger is a pair of columns rather than a table of its own, because
 * there is exactly one per workflow and it has no life without it. The steps
 * are rows — see the migration for why that is not a JSON column.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property string|null $bot_name
 * @property string|null $avatar_path
 * @property string $trigger_type
 * @property array<string, mixed> $trigger_config
 * @property Carbon|null $enabled_at
 * @property string|null $webhook_token_hash
 * @property string|null $webhook_token
 * @property array<string, mixed>|null $webhook_payload
 * @property Carbon|null $webhook_used_at
 * @property Carbon|null $schedule_ran_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'description', 'bot_name', 'trigger_type', 'trigger_config', 'created_by'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    /**
     * A new workflow is off.
     *
     * The opposite of how most things here start, and deliberately so: a
     * workflow is written in several passes — trigger, then steps, then the
     * words in them — and one that ran after the first pass would post half a
     * thought into a channel while somebody is still writing it. Switching it
     * on is how you say you are done.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trigger_config' => '{}',
        'enabled_at' => null,
    ];

    /**
     * Neither of these may reach a payload by accident. The token is the whole
     * credential and the hash is the only thing between a copied row and the
     * right to set this workflow off; showing the URL is deliberate and goes
     * through webhookUrl(), never through a model serialisation.
     *
     * The remembered payload is hidden for a different reason: it holds
     * whatever a sender sent, which may be somebody's name or address, and that
     * belongs on the builder screen and nowhere else.
     *
     * @var list<string>
     */
    protected $hidden = ['webhook_token_hash', 'webhook_token', 'webhook_payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'enabled_at' => 'datetime',
            'webhook_token' => 'encrypted',
            'webhook_payload' => 'array',
            'webhook_used_at' => 'datetime',
            'schedule_ran_at' => 'datetime',
        ];
    }

    /**
     * Long enough that guessing is hopeless, and prefixed so a token found
     * somewhere it should not be is recognisable for what it is.
     */
    private const WEBHOOK_PREFIX = 'wfh_';

    private const WEBHOOK_RANDOM_LENGTH = 48;

    /**
     * Mint a URL for this workflow, and hand back the plain token.
     *
     * Both forms are kept: the hash is what the endpoint looks up, the
     * encrypted copy is what lets somebody see their own URL again later.
     * Minting again replaces both, so the previous URL stops working the moment
     * a new one is handed out.
     */
    public function regenerateWebhookToken(): string
    {
        $token = self::WEBHOOK_PREFIX.Str::random(self::WEBHOOK_RANDOM_LENGTH);

        $this->forceFill([
            'webhook_token_hash' => self::hashWebhookToken($token),
            'webhook_token' => $token,
        ])->save();

        return $token;
    }

    /** The URL to point a sender at, or null when this workflow has none. */
    public function webhookUrl(): ?string
    {
        return $this->webhook_token === null
            ? null
            : route('workflows.webhook', $this->webhook_token);
    }

    public static function hashWebhookToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Remember what arrived, so whoever writes the steps can see it.
     *
     * Only ever the last one, and only up to a size a person would actually
     * read. Something larger is not a sample anybody is going to look at, and
     * the point of keeping one is to have it open while writing a path.
     *
     * @param  array<string, mixed>  $payload
     */
    public function rememberWebhookPayload(array $payload): void
    {
        $encoded = json_encode($payload);

        $this->forceFill([
            'webhook_payload' => $encoded !== false && strlen($encoded) <= self::MAX_PAYLOAD_BYTES
                ? $payload
                // Said out loud rather than left null, which would look exactly
                // like a webhook nothing has ever posted to.
                : ['_truncated' => true],
            'webhook_used_at' => now(),
        ])->save();
    }

    /** The same ceiling the channel webhooks use, and for the same reason. */
    public const MAX_PAYLOAD_BYTES = 16384;

    /**
     * The name this workflow's messages are signed with.
     *
     * A method rather than a column read, because "empty means the workflow's
     * name" is a rule and not a default: a blank box on the builder has to mean
     * the same thing as a workflow written before the box existed. Trimmed on
     * the way in, so a space is empty too — a message signed by a single space
     * would look like the application had lost the name.
     *
     * Never the owner's name, whatever is in the column. A message that looked
     * like a colleague saying something they never said is the one outcome this
     * whole feature must not produce.
     */
    public function botName(): string
    {
        return $this->bot_name ?? $this->name;
    }

    /**
     * Where this workflow's face can be fetched, or null when it has none.
     *
     * Null rather than a default image on purpose: what a bot message shows
     * without one is a mark drawn by the browser, and a URL that always
     * resolved would mean fetching a picture to say "no picture".
     *
     * Stored as a column and a file rather than through the media library, and
     * served through a route rather than off a public disk — the same two
     * decisions a member's face and a workspace's logo are kept under.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : route('avatars.workflow', $this);
    }

    public function isEnabled(): bool
    {
        return $this->enabled_at !== null;
    }

    /**
     * Switching a workflow on and off is a method rather than a fillable
     * column.
     *
     * Deliberately, and the reason is what a mistake here costs: this is the
     * one field that decides whether the thing acts on the workspace at all, so
     * it must not be reachable by a stray key in a form request. A screen that
     * wants to switch one on has to say so.
     */
    public function enable(): void
    {
        $this->forceFill(['enabled_at' => now()])->save();
    }

    public function disable(): void
    {
        $this->forceFill(['enabled_at' => null])->save();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Whose rights the steps run with.
     *
     * Not the person who happened to set the trigger off — see the channel
     * actions for why. A guest who puts an emoji on a message must not become a
     * channel administrator for the length of one run.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Every step in the workflow, at every level.
     *
     * Counting and clearing out read this one. Running does not: a run starts
     * at the top and finds the rest through the forks it passes, and a flat
     * list would run the lanes of a fork whether or not it chose them.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    /**
     * The steps a run begins with: the ones that hang under no fork.
     *
     * @return HasMany<WorkflowStep, $this>
     */
    public function topSteps(): HasMany
    {
        return $this->steps()->atTheTop();
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * The workflows in this workspace waiting for a particular kind of event.
     *
     * The switched-off ones are dropped here rather than in each listener, so
     * that "off" cannot come to mean "still runs, just hidden" in some corner
     * that forgot to ask.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeListeningFor(Builder $query, Workspace $workspace, string $triggerType): void
    {
        $query->where('workspace_id', $workspace->id)
            ->where('trigger_type', $triggerType)
            ->whereNotNull('enabled_at');
    }

    /**
     * Where the trigger's own settings are read.
     *
     * Through one method rather than by reaching into the array everywhere: the
     * column is a free-form bag, so a key spelled one way when it was saved and
     * another way when it is read is simply missing, and this is the seam where
     * that can be noticed.
     */
    public function triggerSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->trigger_config, $key, $default);
    }
}
