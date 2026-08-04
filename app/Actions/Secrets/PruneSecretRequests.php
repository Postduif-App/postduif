<?php

namespace App\Actions\Secrets;

use App\Models\SecretRequest;
use App\Models\SentSecret;
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
     * Clear out the requests and the sent secrets that are done with.
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

        /*
         * The other direction, swept on the same schedule and the same grace.
         *
         * Note that a read secret is already empty — RevealSentSecret blanks the
         * ciphertext the moment it hands it over — so this is not what protects
         * it. What it clears is the row that outlived its usefulness: who sent
         * what to whom, which is worth keeping only as long as the card in the
         * channel still means something.
         */
        SentSecret::query()
            ->where(fn (Builder $query) => $query
                ->where('expires_at', '<', $cutoff)
                ->orWhere('revealed_at', '<', $cutoff))
            ->each(function (SentSecret $secret) use (&$removed): void {
                $secret->delete();
                $removed++;
            });

        return $removed;
    }
}
