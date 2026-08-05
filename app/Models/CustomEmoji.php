<?php

namespace App\Models;

use Database\Factories\CustomEmojiFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A picture a workspace gave a name to.
 *
 * Written into a message as ":naam:" and stored that way too — in the body of a
 * message and in a reaction's emoji column alike. Deliberately the text and not
 * an id: a message is a string that outlives everything around it, and an emoji
 * somebody deletes should leave the words they typed standing rather than a
 * reference nothing can resolve.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $path
 * @property string $mime
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'path', 'mime', 'created_by'])]
class CustomEmoji extends Model
{
    /** @use HasFactory<CustomEmojiFactory> */
    use HasFactory;

    /**
     * What a name may look like.
     *
     * Lower case, no colons, no spaces — the same alphabet the composer's
     * trigger already scans for, so anything that can be stored can also be
     * typed. Here rather than in the request that validates an upload, because
     * the reaction endpoint asks the same question of a shortcode arriving from
     * the other direction.
     *
     * Thirty rather than the column's thirty-two, and that is the whole reason
     * for the number: a reaction is stored as ":name:", in a column that holds
     * thirty-two characters. A name that filled the column would make a
     * shortcode that could not be reacted with.
     */
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,29}$/';

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

    /** What somebody types to use it, colons and all. */
    public function shortcode(): string
    {
        return ':'.$this->name.':';
    }

    public function url(): string
    {
        return route('custom-emoji.show', $this);
    }

    /**
     * The name inside a shortcode, or null when this is not one.
     *
     * The single place that reads the syntax, so the endpoint storing a
     * reaction and the screen drawing one cannot disagree about what counts.
     * Anything else — a unicode emoji, a stray colon, a sentence — gets null.
     */
    public static function nameFromShortcode(string $value): ?string
    {
        if (! str_starts_with($value, ':') || ! str_ends_with($value, ':') || strlen($value) < 3) {
            return null;
        }

        $name = substr($value, 1, -1);

        return preg_match(self::NAME_PATTERN, $name) === 1 ? $name : null;
    }
}
