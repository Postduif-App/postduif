<?php

namespace App\Console\Commands;

use App\Actions\Transfers\PruneTransfers as Pruner;
use App\Actions\UploadLinks\PruneUploadLinks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transfers:prune')]
#[Description('Remove transfers and upload links that have been finished long enough, and their files with them')]
class PruneTransfers extends Command
{
    /**
     * Upload links ride along on this command rather than getting a schedule
     * entry of their own: they are the same feature turned around, with the
     * same grace period, and a second nightly job would be a second thing to
     * forget when the first one moves.
     */
    public function handle(Pruner $pruner, PruneUploadLinks $pruneUploadLinks): int
    {
        $removed = $pruner->handle();
        $removedLinks = $pruneUploadLinks->handle();

        $this->info($removed === 0
            ? __('console.nothing_to_prune')
            : trans_choice('console.transfers_pruned', $removed));

        if ($removedLinks > 0) {
            $this->info(trans_choice('console.upload_links_pruned', $removedLinks));
        }

        return self::SUCCESS;
    }
}
