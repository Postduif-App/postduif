<?php

namespace App\Models;

use Database\Factories\ChannelSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group somebody made in their own sidebar.
 *
 * Belongs to one member in one workspace. Nobody else sees it, nobody else can
 * change it, and a channel in somebody's section is not in anybody else's.
 *
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property string $name
 * @property int $position
 */
#[Fillable(['user_id', 'workspace_id', 'name', 'position'])]
class ChannelSection extends Model
{
    /** @use HasFactory<ChannelSectionFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The channels somebody filed here, in the order they arranged them.
     *
     * @return BelongsToMany<Channel, $this>
     */
    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class)
            ->withPivot('position')
            ->orderByPivot('position');
    }

    /** @param  Builder<$this>  $query */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
