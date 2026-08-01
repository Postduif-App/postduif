<?php

namespace App\Jobs;

use App\Actions\Chat\FetchLinkPreview;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Find out what a link is, away from the request that mentioned it.
 *
 * On a queue for two reasons, and the second is the important one: a slow page
 * must not hold up a message, and a page that answers strangely must not be
 * able to fail the request that sent it. Everything that can go wrong is
 * already written to the row by the action, so this job has nothing to retry.
 */
class FetchLinkPreviewJob implements ShouldQueue
{
    use Queueable;

    /**
     * Once. A link that could not be read has its reason stored, and asking
     * again is exactly the outgoing request nobody wanted.
     */
    public int $tries = 1;

    public function __construct(public readonly string $url) {}

    public function handle(FetchLinkPreview $fetchLinkPreview): void
    {
        $fetchLinkPreview->handle($this->url);
    }

    /**
     * One job per URL in flight, rather than one per message that carries it.
     */
    public function uniqueId(): string
    {
        return $this->url;
    }
}
