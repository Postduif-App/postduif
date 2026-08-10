<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A document that belongs to a channel: the part of the conversation worth
 * keeping in one piece.
 *
 * A channel is a stream, and everything in it is equally old by tomorrow. Some
 * things are not like that — the way this customer wants their invoices, what
 * the on-call rota is, what was decided in March. Those get retold every few
 * weeks by whoever remembers, until somebody remembers wrong. A document is the
 * place where that kind of thing stops being retold.
 *
 * Several per channel rather than one, because "the channel's page" would
 * immediately become a page with headings for unrelated subjects that different
 * people maintain.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $channel_id
 * @property int $number
 * @property string $title
 * @property array<string, mixed> $body
 * @property string $body_text
 * @property int $version
 * @property int $created_by
 * @property int|null $updated_by
 * @property-read User|null $creator
 * @property-read User|null $editor
 * @property-read Channel $channel
 * @property-read Workspace $workspace
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'channel_id', 'number', 'title', 'body', 'body_text', 'created_by', 'updated_by'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    /**
     * A document that has just been made is read back before it is refetched —
     * the editor loads straight from the create response — and both of these
     * would be null until then.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'body_text' => '',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * The document, decoded on the way out and encoded on the way in.
     *
     * Hand-written rather than the 'array' cast, for one reason that only shows
     * up when the document is empty. PHP has a single array type and json_encode
     * writes an empty one as `[]`, but the editor reads its value as a map of
     * block id to block — and in JSON `[]` and `{}` are not the same thing. An
     * empty document would come back as a list and the editor would be handed
     * something it does not accept.
     *
     * Only the top level is forced. JSON_FORCE_OBJECT would do it everywhere,
     * which would turn each block's `value` and every `children` into objects
     * keyed "0", "1" — a document Slate cannot read at all.
     *
     * The second type parameter is what may be assigned, not what is stored:
     * Laravel's set callback takes TSet and returns whatever goes to the
     * database. Both are the array, because that is what a caller writes and
     * what a reader gets back — the string only exists in between.
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

    /**
     * Documents are addressed by their number, the same as tickets.
     *
     * Scoped route bindings resolve it within the channel, so a number from
     * another channel is a 404 rather than somebody else's document.
     */
    public function getRouteKeyName(): string
    {
        return 'number';
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

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The first line or so of the document, for the list.
     *
     * Taken from the flattened text rather than from the JSON: the list should
     * not have to know what a block looks like, which is the whole reason
     * body_text is stored in the first place.
     */
    public function excerpt(int $characters = 160): string
    {
        return Str::limit(Str::squish($this->body_text), $characters);
    }

    /**
     * An empty document, in the shape the editor expects to be handed.
     *
     * Yoopta reads its value as a map of block id to block, so nothing is not
     * an empty array in the general sense but an empty map — and in JSON those
     * two are `[]` and `{}`, which the editor does not confuse.
     *
     * @return array<string, mixed>
     */
    public static function emptyBody(): array
    {
        return [];
    }

    /**
     * Documents in the channels the given user is allowed to see.
     *
     * Leans on the channel rule rather than restating it, the same way tickets
     * do: a document is only ever as visible as the channel it sits in, and there
     * should be exactly one place where that is decided.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereIn('channel_id', Channel::query()->visibleTo($user)->select('id'));
    }

    /**
     * Documents whose title or text answers the given search.
     *
     * The same tsvector arrangement the messages use — see the migration for
     * why the title is weighted above the body — so a workspace-wide search
     * asks both in one language rather than two.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, string $terms): void
    {
        $query->whereRaw(
            "search_vector @@ plainto_tsquery('simple', ?)",
            [$terms]
        );
    }

    /**
     * List order: whatever was worked on most recently, first.
     *
     * By updated_at rather than by title or number. A channel's documents are
     * not a catalogue somebody browses; they are a handful of documents of
     * which one or two are live and the rest are settled.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInListOrder(Builder $query): void
    {
        $query->orderByDesc('updated_at')->orderByDesc('id');
    }
}
