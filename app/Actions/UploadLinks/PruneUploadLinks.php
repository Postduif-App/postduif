<?php

namespace App\Actions\UploadLinks;

use App\Actions\Transfers\PruneTransfers;
use App\Models\UploadLink;
use Illuminate\Database\Eloquent\Builder;

class PruneUploadLinks
{
    /**
     * Take the finished upload links off the disk, and what came in with them.
     *
     * The same grace period as a transfer, for the same Monday morning: a link
     * that ran out over the weekend may be worth putting forward rather than
     * asking the customer to start again.
     *
     * One at a time rather than a mass delete — the files go on the model's
     * delete event, and a query builder delete fires none. See
     * UploadLink::booted().
     *
     * @return int How many were removed.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(PruneTransfers::GRACE_DAYS);

        $removed = 0;

        UploadLink::query()
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', $cutoff)
                ->orWhere('revoked_at', '<', $cutoff))
            ->each(function (UploadLink $link) use (&$removed): void {
                $link->delete();
                $removed++;
            });

        return $removed;
    }
}
