<?php

namespace App\Models;

use Database\Factories\DocumentRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A document as it stood before somebody changed it.
 *
 * See the migration for why this exists at all. What matters here: a revision
 * is written once and never touched again, which is why it has no updated_at
 * and no way to change its body — a history that can be edited is not a history.
 *
 * @property int $id
 * @property int $document_id
 * @property int|null $created_by
 * @property array<string, mixed> $body
 * @property string $body_text
 * @property Carbon|null $created_at
 * @property-read Document $document
 * @property-read User|null $author
 */
#[Fillable(['document_id', 'created_by', 'body', 'body_text'])]
class DocumentRevision extends Model
{
    /** @use HasFactory<DocumentRevisionFactory> */
    use HasFactory;

    /** Written on insert and never again — see the migration. */
    public const UPDATED_AT = null;

    /**
     * The same hand-written cast the document has, and for the same reason:
     * PHP writes an empty array as `[]` and the editor reads its value as a map
     * of block id to block, which in JSON is `{}`. Restoring a revision that
     * came back as a list would hand the editor something it cannot open.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>>
     */
    protected function body(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): array {
                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : [];
            },
            set: fn (array $value): string => $value === [] ? '{}' : (string) json_encode($value),
        );
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Who wrote the text in this revision.
     *
     * Not who caused it to be recorded. A revision is written by the save that
     * replaced it, and crediting that person would put the wrong name against
     * every version in the list.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The first line or so, so the list says something without opening it. */
    public function excerpt(int $characters = 120): string
    {
        return Str::limit(Str::squish($this->body_text), $characters);
    }

    /** @param  Builder<$this>  $query */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
