<?php

namespace App\Actions\Chat;

use App\Models\InboxItem;

class PruneInboxItems
{
    /**
     * How long a row stays after it has been read.
     *
     * The inbox is a worklist rather than an archive: what has been dealt with
     * has served its purpose, and the channel it points at keeps the actual
     * conversation forever. A month is long enough that "what was that thing
     * from a few weeks ago" still has an answer here, and short enough that a
     * table growing with every reply in every busy thread does not become the
     * largest one in the database.
     */
    public const KEEP_READ_DAYS = 30;

    /**
     * Take away what has been dealt with.
     *
     * Unread rows are never touched, however old. Something nobody got round to
     * is exactly what an inbox is for, and quietly removing it would turn "I
     * have nothing waiting" into a statement the application cannot back up.
     *
     * A mass delete rather than one at a time, unlike PruneTransfers beside it:
     * nothing hangs off these rows — no files, no model events — so there is
     * nothing that a query builder delete would skip.
     *
     * @return int How many were removed.
     */
    public function handle(): int
    {
        return InboxItem::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays(self::KEEP_READ_DAYS))
            ->delete();
    }
}
