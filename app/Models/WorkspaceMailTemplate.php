<?php

namespace App\Models;

use App\Enums\MailTemplateKind;
use Database\Factories\WorkspaceMailTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One workspace's own words for one mail in one language.
 *
 * Every field is nullable and a null field is not "empty text" but "we never
 * said" — the platform's own sentence is used instead. That distinction is the
 * reason none of these have defaults: an empty string saved by a form that
 * submitted a blank field would be a workspace silently deciding its mail
 * should have no heading, and nobody means that.
 *
 * The row exists to be overwritten in place. There is one per workspace, kind
 * and language, and the unique index on the table says so.
 *
 * @property int $id
 * @property int $workspace_id
 * @property MailTemplateKind $kind
 * @property string $locale
 * @property string|null $subject
 * @property string|null $heading
 * @property string|null $body
 * @property string|null $button_label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'kind',
    'locale',
    'subject',
    'heading',
    'body',
    'button_label',
])]
class WorkspaceMailTemplate extends Model
{
    /** @use HasFactory<WorkspaceMailTemplateFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeFor(Builder $query, MailTemplateKind $kind, string $locale): Builder
    {
        return $query->where('kind', $kind)->where('locale', $locale);
    }

    /**
     * Whether this row still says anything.
     *
     * A row where every field has been cleared is indistinguishable from no row
     * at all, and both mean the platform text — see the migration. Worth being
     * able to ask, so that saving a screen somebody emptied out deletes the row
     * instead of leaving a tombstone that every later query has to step over.
     */
    public function isEmpty(): bool
    {
        return blank($this->subject)
            && blank($this->heading)
            && blank($this->body)
            && blank($this->button_label);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MailTemplateKind::class,
        ];
    }
}
