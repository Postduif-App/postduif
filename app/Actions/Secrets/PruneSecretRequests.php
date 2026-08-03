<?php

namespace App\Actions\Secrets;

use App\Models\SecretRequest;
use Illuminate\Database\Eloquent\Builder;

class PruneSecretRequests
{
    /**
     * How long a finished request survives.
     *
     * Shorter than the week a transfer gets, and deliberately so. A transfer
     * that is cleared too early costs somebody an upload; a secret that is kept
     * too long costs somebody a password. When the two pull in opposite
     * directions, this one gives way.
     */
    public const GRACE_DAYS = 2;

    /**
     * Clear out the requests that are done with.
     *
     * Deleted rather than emptied, and one at a time rather than in a mass
     * delete: the cascade takes the keys and the encrypted values with the row,
     * and a query-builder delete would fire no model events for anything that
     * later hangs off these.
     *
     * @return int How many were removed.
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(self::GRACE_DAYS);

        $removed = 0;

        SecretRequest::query()
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', $cutoff)
                ->orWhere('revoked_at', '<', $cutoff))
            ->each(function (SecretRequest $request) use (&$removed): void {
                $request->delete();
                $removed++;
            });

        return $removed;
    }
}
