<?php

namespace App\Jobs;

use App\Actions\Chat\AnnounceLinkPreview;
use App\Actions\Chat\FetchLinkPreview;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
class FetchLinkPreviewJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Once. A link that could not be read has its reason stored, and asking
     * again is exactly the outgoing request nobody wanted.
     */
    public int $tries = 1;

    public function __construct(public readonly string $url) {}

    public function handle(FetchLinkPreview $fetchLinkPreview, AnnounceLinkPreview $announceLinkPreview): void
    {
        /*
         * Fetch, then go back and tell the conversations that already have the
         * message on screen. Without the second half the card only appears on
         * the next reload, which is how a working feature reads as a broken
         * one — see LinkPreviewAttached.
         */
        $announceLinkPreview->handle($fetchLinkPreview->handle($this->url));
    }

    /**
     * One job per URL in flight, rather than one per message that carries it.
     *
     * This only means anything because of ShouldBeUnique above — uniqueId() on
     * its own is a method Laravel never calls. Without the interface, ten
     * channels being given the same new link in the same minute was ten
     * outgoing requests: the row that would have stopped the second one does
     * not exist until the first has finished.
     */
    public function uniqueId(): string
    {
        return $this->url;
    }
}
