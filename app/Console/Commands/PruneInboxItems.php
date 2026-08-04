<?php

namespace App\Console\Commands;

use App\Actions\Chat\PruneInboxItems as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('inbox:prune')]
#[Description('Remove inbox rows that have been read long enough')]
class PruneInboxItems extends Command
{
    public function handle(Pruner $pruner): int
    {
        $removed = $pruner->handle();

        $this->info($removed === 0
            ? __('console.nothing_to_prune')
            : trans_choice('console.inbox_pruned', $removed));

        return self::SUCCESS;
    }
}
