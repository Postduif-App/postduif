<?php

namespace App\Actions\Transfers;

use App\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;

class PruneTransfers
{
    /**
     * How long a finished transfer stays around before its bytes go.
     *
     * Not zero, and the reason is the ordinary Monday morning: a link expires
     * over the weekend, the customer says so, and the sender wants to put the
     * date forward rather than upload two gigabytes again. A week is long
     * enough for that conversation to happen and short enough that the disk is
     * not being paid for indefinitely.
     */
    public const GRACE_DAYS = 7;

    /**
     * Take the finished transfers off the disk.
     *
     * Deleted rather than emptied: the row carries the download log, and the
     * log carries IP addresses of people who never had an account here. Keeping
     * a tombstone for tidiness would mean keeping those, which is the one thing
     * this command exists to stop.
     *
     * Deleted one at a time rather than in a mass delete, and deliberately: the
     * media library removes the files on the model's delete event, and a query
     * builder delete fires no events — it would clear the rows and leave every
     * byte on disk forever, which is the exact opposite of the point.
     *
     * @return int How many were removed.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(self::GRACE_DAYS);

        $removed = 0;

        Transfer::query()
            /*
             * Finished, and finished a while ago. Withdrawal and expiry both
             * count; being used up does not — a transfer that hit its download
             * ceiling on day one still has weeks to run, and the sender may
             * well raise the ceiling.
             */
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', $cutoff)
                ->orWhere('revoked_at', '<', $cutoff))
            ->each(function (Transfer $transfer) use (&$removed): void {
                $transfer->delete();
                $removed++;
            });

        return $removed;
    }
}
