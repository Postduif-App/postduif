<?php

namespace App\Models;

use Database\Factories\ChannelTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A label that hangs on any number of channels in one workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $slug
 */
#[Fillable(['workspace_id', 'name', 'slug'])]
class ChannelTag extends Model
{
    /** @use HasFactory<ChannelTagFactory> */
    use HasFactory;

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<Channel, $this> */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class);
    }

    /**
     * How a name becomes the thing uniqueness is judged on.
     *
     * One place, because the same answer is needed when a tag is created and
     * when one is looked up by what somebody typed — and two spellings of that
     * rule is how "Klant" and "klant" end up as two tags.
     */
    public static function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * Find the tag by what somebody typed, or make it.
     *
     * Tags are created by using them rather than by managing them first: being
     * sent to a separate screen to declare a label before you can attach it is
     * how a feature like this goes unused.
     */
    public static function claim(Workspace $workspace, string $name): self
    {
        $name = trim($name);

        return static::firstOrCreate(
            ['workspace_id' => $workspace->id, 'slug' => static::slugFor($name)],
            ['name' => $name],
        );
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('name');
    }
}
