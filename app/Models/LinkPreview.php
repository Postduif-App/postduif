<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What a shared link turned out to be.
 *
 * Keyed by the URL rather than by the message that carried it: the same link
 * lands in ten channels, and fetching it ten times means telling the other side
 * ten times that somebody here is looking.
 *
 * @property int $id
 * @property string $url
 * @property string $url_hash
 * @property string|null $title
 * @property string|null $description
 * @property string|null $image_url
 * @property string|null $site_name
 * @property Carbon|null $fetched_at
 * @property string|null $failed_reason
 */
#[Fillable(['url', 'url_hash', 'title', 'description', 'image_url', 'site_name', 'fetched_at', 'failed_reason'])]
class LinkPreview extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    /**
     * How a URL is looked up.
     *
     * Hashed because a URL can be longer than an index allows, and because it
     * makes the lookup one exact comparison rather than a string match over a
     * 2 KB column.
     */
    public static function hash(string $url): string
    {
        return hash('sha256', $url);
    }

    /** Whether there is anything worth drawing. */
    public function isUsable(): bool
    {
        return $this->failed_reason === null && $this->title !== null;
    }
}
