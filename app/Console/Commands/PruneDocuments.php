<?php

namespace App\Console\Commands;

use App\Actions\Documents\PruneDocuments as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('documents:prune')]
#[Description('Finally remove documents that were deleted long enough ago, and their files with them')]
class PruneDocuments extends Command
{
    public function handle(Pruner $pruner): int
    {
        $removed = $pruner->handle();

        $this->info($removed === 0
            ? __('console.nothing_to_prune')
            : trans_choice('console.documents_pruned', $removed));

        return self::SUCCESS;
    }
}
