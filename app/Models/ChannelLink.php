<?php

namespace App\Models;

use Database\Factories\ChannelLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A button in the bar above a channel.
 *
 * It does one of two things and never both: it opens a URL outside the app, or
 * it starts a workflow in it. Which one is decided by whichever of the two
 * columns is filled — the database refuses a row that answers neither or both,
 * see the migration.
 *
 * @property int $id
 * @property int $channel_id
 * @property int|null $workflow_id
 * @property string $label
 * @property string|null $url
 * @property int $position
 */
#[Fillable(['channel_id', 'workflow_id', 'label', 'url', 'position'])]
class ChannelLink extends Model
{
    /** @use HasFactory<ChannelLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * Whether pressing this starts something here rather than going somewhere
     * else. Asked of the workflow rather than of the url, because that is the
     * column that decides what the button *is*.
     */
    public function startsWorkflow(): bool
    {
        return $this->workflow_id !== null;
    }

    /**
     * The order the bar draws them in.
     *
     * The id breaks the tie rather than leaving it to the database: two links
     * can share a position while one of them is being moved, and a bar that
     * swaps them at random between two page loads reads as a bug.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeInOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
