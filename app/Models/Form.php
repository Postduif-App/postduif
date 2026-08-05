<?php

namespace App\Models;

use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A set of questions somebody in the workspace put up, and a place for the
 * answers to land.
 *
 * The thing a form is *not* is a channel feature. It is made once and can be
 * put in several channels, or in none at all and only behind a link — which is
 * why it hangs off the workspace rather than off a channel, unlike a poll.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property int|null $notify_channel_id
 * @property string $title
 * @property string|null $description
 * @property string|null $share_token
 * @property bool $allows_multiple_submissions
 * @property Carbon|null $closes_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $author The member who wrote it, until they leave.
 * @property-read Channel|null $notifyChannel
 */
#[Fillable([
    'workspace_id',
    'created_by',
    'notify_channel_id',
    'title',
    'description',
    'allows_multiple_submissions',
    'closes_at',
])]
class Form extends Model
{
    /** @use HasFactory<FormFactory> */
    use HasFactory, HasUlids;

    /**
     * The link is never mass-assigned: it is handed out and withdrawn by
     * share() and withdrawLink(), so that "there is a public link now" is
     * always a deliberate act rather than a field that came along with an
     * update.
     */
    protected $hidden = ['share_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allows_multiple_submissions' => 'boolean',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Channel, $this> */
    public function notifyChannel(): BelongsTo
    {
        return $this->belongsTo(Channel::class, 'notify_channel_id');
    }

    /** @return HasMany<FormField, $this> */
    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('position');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** @return HasManyThrough<FormAnswer, FormSubmission, $this> */
    public function answers(): HasManyThrough
    {
        return $this->hasManyThrough(FormAnswer::class, FormSubmission::class);
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null
            || ($this->closes_at !== null && $this->closes_at->isPast());
    }

    public function isShared(): bool
    {
        return $this->share_token !== null;
    }

    /**
     * Whether this form can be filled in at all.
     *
     * A form with no questions is the one state the screens have to refuse
     * rather than render: it would take an empty submission and send a DM
     * saying nothing.
     */
    public function acceptsAnswers(): bool
    {
        return ! $this->isClosed() && $this->fields()->exists();
    }

    public function hasSubmissionFrom(User $user): bool
    {
        return $this->submissions()->where('submitted_by', $user->id)->exists();
    }

    /**
     * Hand out a public link, or replace the one there is.
     *
     * Replacing rather than reusing is the point of returning a fresh token
     * every time somebody shares again: the old URL stops working, which is the
     * only thing "withdraw and share again" can honestly mean.
     */
    public function share(): string
    {
        $token = Str::random(48);

        $this->forceFill(['share_token' => $token])->save();

        return $token;
    }

    public function withdrawLink(): void
    {
        $this->forceFill(['share_token' => null])->save();
    }

    /**
     * The address somebody outside the workspace uses.
     *
     * Null when there is no link, so a caller cannot accidentally build a URL
     * for a form that was never shared.
     */
    public function publicUrl(): ?string
    {
        return $this->share_token === null
            ? null
            : route('forms.public.show', $this->share_token);
    }

    /**
     * The next key a new field may use, kept unique within this form.
     *
     * Lives on the form rather than on the field because uniqueness is a fact
     * about the set, and because the builder needs to ask for one before a
     * field exists.
     */
    public function keyFor(string $label): string
    {
        /*
         * Letters, digits and underscores, and nothing else.
         *
         * snake() alone is not enough, because a question is written as a
         * question: "Waarom?" would become the key "waarom?", and the variable
         * syntax a workflow reads it with — see ResolveVariables::PATTERN —
         * accepts no punctuation at all. The key would exist and be unreachable,
         * which is the worst of the three possible outcomes.
         */
        $base = Str::of($label)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->limit(50, '')
            ->trim('_')
            ->toString();

        if ($base === '') {
            $base = 'veld';
        }

        $key = $base;
        $suffix = 2;

        while ($this->fields()->where('key', $key)->exists()) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    /** @param  Builder<Form>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at')
            ->where(fn (Builder $query) => $query->whereNull('closes_at')->orWhere('closes_at', '>', now()));
    }
}
