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
     * The links worth looking at, which is the front of the list.
     *
     * What counts as a link is LinkPreview::urlsIn's business, so the queuer
     * and the card agree about where a URL ends without either of them holding
     * a copy of the expression.
     *
     * @return list<string>
     */
    private function urlsIn(string $body): array
    {
        return array_slice(LinkPreview::urlsIn($body), 0, self::PER_MESSAGE);
    }
}
