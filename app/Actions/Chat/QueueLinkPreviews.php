<?php

namespace App\Actions\Chat;

use App\Jobs\FetchLinkPreviewJob;
use App\Models\LinkPreview;
use App\Models\Message;

/**
 * Notice the links in a message and go and find out what they are.
 *
 * Only where the workspace asked for it. That switch is off by default, and
 * this is the one place that reads it — fetching a preview means our server
 * opens somebody's link, which is visible at the other end.
 */
class QueueLinkPreviews
{
    /**
     * One per message. A message with a list of ten links is a list, not ten
     * previews, and fetching all of them would be ten outgoing requests for one
     * line of chat.
     */
    private const PER_MESSAGE = 1;

    public function handle(Message $message): void
    {
        if (! $message->workspace->link_previews_enabled) {
            return;
        }

        foreach ($this->urlsIn($message->body) as $url) {
            // Already known, including already refused: the row is the record
            // that this link has been looked at, and looking again is the thing
            // being avoided.
            if (LinkPreview::query()->where('url_hash', LinkPreview::hash($url))->exists()) {
                continue;
            }

            FetchLinkPreviewJob::dispatch($url);
        }
    }

    /**
     * The links in a message, in the order they appear.
     *
     * Deliberately strict about what counts: only http and https, and only up
     * to whitespace. Anything cleverer would start matching things nobody
     * meant as a link.
     *
     * @return array<int, string>
     */
    private function urlsIn(string $body): array
    {
        preg_match_all('/\bhttps?:\/\/[^\s<>"\']+/i', $body, $matches);

        return array_slice(
            array_values(array_unique(array_map(
                // Trailing punctuation is almost always the sentence, not the
                // URL: "kijk op https://voorbeeld.nl." ends with a full stop.
                fn (string $url): string => rtrim($url, '.,;:!?)'),
                $matches[0],
            ))),
            0,
            self::PER_MESSAGE,
        );
    }
}
