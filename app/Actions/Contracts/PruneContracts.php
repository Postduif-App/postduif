<?php

namespace App\Actions\Contracts;

use App\Enums\ContractStatus;
use App\Events\ContractExpired;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Two jobs on a timer: close what has run out, and clear away what came to
 * nothing.
 *
 * They are one command because they are two ends of the same life. A contract
 * whose deadline passed is not yet rubbish — somebody may well want to see who
 * did sign, and put the date forward — so it is first marked Expired and only
 * removed a good while later.
 *
 * What is never touched is a completed contract. That is the piece of evidence
 * the whole feature exists to produce: somebody put their name under something
 * and holds a copy that says so, and deleting our half of that on a timer would
 * be destroying a record on the strength of nothing but the calendar. See
 * ContractStatus::isEvidence().
 */
class PruneContracts
{
    /**
     * @return array{expired: int, removed: int} What was closed, and what went.
     */
    public function handle(): array
    {
        return [
            'expired' => $this->closeWhatRanOut(),
            'removed' => $this->clearAwayWhatCameToNothing(),
        ];
    }

    /**
     * Turn contracts whose deadline has passed to Expired.
     *
     * Still one UPDATE over the set, but the ids are read first — and that is
     * the whole reason this is no longer a single statement. Something does
     * hang off this transition now: a workflow can be waiting for a contract to
     * run out, and an UPDATE over a set fires no model events and dispatches
     * nothing. A trigger hung off an event nobody sends is a workflow that
     * quietly never runs, which is worse than not offering the trigger at all.
     *
     * The extra SELECT is a page of ids on a nightly command. It buys an event
     * per contract, which is what the announcement has to be: "er zijn er zeven
     * verlopen" is not something a workflow can act on.
     *
     * Note what the status column is *not* doing in the meantime. Anything that
     * asks "may this still be signed" asks Contract::isSignable(), which
     * compares the date itself — so a deadline that passed an hour ago has
     * passed, whether or not this has run since. This exists so the overview and
     * the counters read right, not to enforce the deadline.
     */
    private function closeWhatRanOut(): int
    {
        $expired = Contract::query()
            ->where('status', ContractStatus::Sent->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->pluck('id');

        if ($expired->isEmpty()) {
            return 0;
        }

        Contract::query()
            ->whereIn('id', $expired)
            ->update(['status' => ContractStatus::Expired->value]);

        /*
         * Announced after the update, so anything that goes and reads the row
         * finds it already Expired rather than racing the statement that put it
         * there.
         */
        foreach ($expired as $id) {
            ContractExpired::dispatch((string) $id);
        }

        return $expired->count();
    }

    /**
     * Delete the contracts that ended without being signed, once the grace
     * period is up.
     *
     * Deleted one at a time rather than in a mass delete, and deliberately: the
     * media library removes the PDF on the model's delete event, and a query
     * builder delete fires no events — it would clear the rows and leave every
     * byte on disk forever, which is the exact opposite of the point. The same
     * trap PruneTransfers spells out.
     *
     * Deleted rather than emptied, too. The row carries the signers' names,
     * addresses and IP addresses — people who often have no account here — and
     * keeping a tombstone for tidiness would mean keeping those.
     */
    private function clearAwayWhatCameToNothing(): int
    {
        $cutoff = now()->subDays((int) config('contracts.grace_days'));

        $removed = 0;

        Contract::query()
            ->whereIn('status', [ContractStatus::Expired->value, ContractStatus::Cancelled->value])
            /*
             * Measured from the ending rather than from created_at, so the grace
             * period means what it says: a month to change your mind about this
             * contract, counted from the day it stopped.
             *
             * cancelled_at may be null on an expired one and the other way
             * round, which is why the two are asked separately rather than
             * coalesced — and why expires_at is the fallback for a contract that
             * was marked Expired before completed_at or cancelled_at existed.
             */
            ->where(fn (Builder $query) => $query
                ->where('cancelled_at', '<', $cutoff)
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('cancelled_at')
                    ->where('expires_at', '<', $cutoff)))
            ->each(function (Contract $contract) use (&$removed): void {
                $contract->delete();
                $removed++;
            });

        return $removed;
    }
}
