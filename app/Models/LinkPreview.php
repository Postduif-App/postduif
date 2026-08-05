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

    /**
     * The links in a message, in the order they appear.
     *
     * The single place that reads the syntax, for the reason
     * CustomEmoji::nameFromShortcode is: three places used to carry this
     * expression — the one that queues a look-up, the one that draws a card,
     * and the one that decides which messages to tell about a preview — and the
     * moment two of them disagreed about where a URL ends, a link would be
     * fetched under one spelling and looked up under another. Nothing would
     * break loudly; the card would simply never appear.
     *
     * Deliberately strict about what counts: only http and https, and only up
     * to whitespace. Anything cleverer starts matching things nobody meant as a
     * link.
     *
     * @return list<string>
     */
    public static function urlsIn(string $body): array
    {
        preg_match_all('/\bhttps?:\/\/[^\s<>"\']+/i', $body, $matches);

        return array_values(array_unique(array_map(
            // Trailing punctuation is almost always the sentence, not the URL:
            // "kijk op https://voorbeeld.nl." ends with a full stop.
            fn (string $url): string => rtrim($url, '.,;:!?)'),
            $matches[0],
        )));
    }

    /** The first link in a message, or null when it carries none. */
    public static function firstUrlIn(string $body): ?string
    {
        return self::urlsIn($body)[0] ?? null;
    }
}
