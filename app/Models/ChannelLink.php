<?php

namespace App\Models;

use Database\Factories\ChannelLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A button in the bar above a channel, pointing somewhere outside the app.
 *
 * @property int $id
 * @property int $channel_id
 * @property string $label
 * @property string $url
 * @property int $position
 */
#[Fillable(['channel_id', 'label', 'url', 'position'])]
class ChannelLink extends Model
{
    /** @use HasFactory<ChannelLinkFactory> */
    use HasFactory;

    /** @return BelongsTo<Channel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
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
