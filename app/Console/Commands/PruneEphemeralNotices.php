<?php

namespace App\Console\Commands;

use App\Actions\Chat\PruneEphemeralNotices as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:prune-notices')]
#[Description('Remove the one-person notices nobody is going to read again')]
class PruneEphemeralNotices extends Command
{
    public function handle(Pruner $pruner): int
    {
        $removed = $pruner->handle();

        $this->info($removed === 0
            ? __('console.nothing_to_prune')
            : trans_choice('console.notices_pruned', $removed));

        return self::SUCCESS;
    }
}
