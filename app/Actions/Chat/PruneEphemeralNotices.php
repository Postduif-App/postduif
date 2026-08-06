<?php

namespace App\Actions\Chat;

use App\Models\EphemeralNotice;

/**
 * Clear out the receipts nobody is going to read again.
 *
 * Two thresholds, because a notice has two ways of being finished with. One
 * that named a moment is done when that moment passes. One that did not — a
 * receipt for something that failed, which stays until it is dismissed — is
 * done when it is old enough that whoever it was for has plainly moved on.
 */
class PruneEphemeralNotices
{
    /**
     * A week for the ones that wait to be dismissed. Long enough to survive a
     * holiday, short enough that the table stays a scratchpad rather than an
     * archive of everything anybody was ever told.
     */
    public const KEEP_DAYS = 7;

    public function handle(): int
    {
        return EphemeralNotice::query()
            ->where(function ($query): void {
                $query->where('expires_at', '<', now())
                    ->orWhere('created_at', '<', now()->subDays(self::KEEP_DAYS));
            })
            ->delete();
    }
}
