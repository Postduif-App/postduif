<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Stops a change that just arrived from Backlog from being sent straight back
 * to it.
 *
 * Without this, a status change would round-trip forever: Backlog tells us an
 * issue was closed, ProcessBacklogWebhookJob closes the ticket, TicketChanged
 * fires, SyncTicketToBacklog hears it and tells Backlog the issue was closed,
 * Backlog's own webhook fires again, and so on.
 *
 * The guard is a cache flag rather than a column, because the fact it records
 * — "this ticket's next change came from Backlog, not from here" — is true for
 * a few seconds at most. ProcessBacklogWebhookJob marks a ticket immediately
 * before it applies the change; SyncTicketToBacklog checks and consumes the
 * mark before deciding whether to sync. A mark that was never consumed simply
 * expires — the two are in the same request/job chain, never minutes apart.
 */
class BacklogSyncGuard
{
    /**
     * Long enough that the listener reacting to the event this job's own write
     * causes always finds the mark; short enough that an ordinary change made
     * a few seconds later is never mistaken for an echo.
     */
    private const TTL_SECONDS = 30;

    public static function markEchoed(int $ticketId): void
    {
        Cache::put(self::key($ticketId), true, self::TTL_SECONDS);
    }

    /**
     * Whether this ticket's most recent change came from Backlog.
     *
     * Consumes the mark: read once, by the one listener that needs to know,
     * so a second unrelated change to the same ticket a moment later is never
     * mistaken for the same echo.
     */
    public static function wasEchoed(int $ticketId): bool
    {
        return Cache::pull(self::key($ticketId)) !== null;
    }

    private static function key(int $ticketId): string
    {
        return "backlog-sync:echo:ticket:{$ticketId}";
    }
}
