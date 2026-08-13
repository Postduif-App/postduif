<?php

namespace App\Actions\Polls;

use App\Events\PollClosed;
use App\Models\Poll;

/**
 * Notice the polls whose own moment has passed, and say so once.
 *
 * A poll can be shut in two ways and only one of them used to happen *at* a
 * moment: somebody pressing stop. The other — closes_at going by — was worked
 * out wherever the poll was read, so a poll that ran out at midnight had been
 * closed since midnight without anything having run. Which is fine for a card
 * in a channel and useless for a workflow: half of all polls end that way, and
 * the poll-closed trigger simply never fired for them.
 *
 * The shape is PruneContracts': read the ids, then one event per row rather
 * than a single mass update. A mass update fires no model events and dispatches
 * nothing, and "er zijn er zeven verlopen" is not something a workflow can act
 * on — it wants this poll, with its tally.
 *
 * What this deliberately does not do is stamp closed_at. That column means
 * somebody pressed stop, the card in the channel reads differently for the two,
 * and reopen() undoes them separately. Borrowing it to make the event fire
 * would have been the cheap fix at the cost of the distinction the whole
 * feature keeps.
 */
class SettlePolls
{
    /** @return int How many polls were announced. */
    public function handle(): int
    {
        $due = Poll::query()
            ->whereNull('settled_at')
            ->whereNotNull('closes_at')
            ->where('closes_at', '<', now())
            ->get(['id', 'closed_at']);

        if ($due->isEmpty()) {
            return 0;
        }

        Poll::query()->whereIn('id', $due->pluck('id'))->update(['settled_at' => now()]);

        $announced = 0;

        foreach ($due as $poll) {
            /*
             * Already stopped by hand, and already announced when that
             * happened. The deadline arriving afterwards closes nothing that
             * was still open, so it is marked dealt with above and passed over
             * here — announcing would tell a workflow twice about one ending.
             */
            if ($poll->closed_at !== null) {
                continue;
            }

            /*
             * Dispatched after the stamp, so anything that goes and reads the
             * row finds it settled rather than racing the statement that put it
             * there. The same ordering PruneContracts uses, for the same reason.
             */
            PollClosed::dispatch($poll->id);
            $announced++;
        }

        return $announced;
    }
}
