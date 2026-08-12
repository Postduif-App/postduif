<?php

namespace App\Console\Commands;

use App\Actions\Contracts\PruneContracts as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('contracts:prune')]
#[Description('Close contracts whose deadline has passed, and remove the ones that came to nothing')]
class PruneContracts extends Command
{
    public function handle(Pruner $pruner): int
    {
        ['expired' => $expired, 'removed' => $removed] = $pruner->handle();

        if ($expired === 0 && $removed === 0) {
            $this->info(__('console.nothing_to_prune'));

            return self::SUCCESS;
        }

        /*
         * Both numbers, always, once either is non-zero — including the one that
         * is zero. "3 verlopen, 0 verwijderd" says the command did both halves
         * of its job and found nothing to delete; printing only the non-zero
         * line leaves somebody reading the output wondering whether the other
         * half ran at all.
         */
        $this->info(trans_choice('console.contracts_expired', $expired));
        $this->info(trans_choice('console.contracts_pruned', $removed));

        return self::SUCCESS;
    }
}
