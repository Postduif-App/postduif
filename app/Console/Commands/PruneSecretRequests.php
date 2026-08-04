<?php

namespace App\Console\Commands;

use App\Actions\Secrets\PruneSecretRequests as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('secrets:prune')]
#[Description('Remove secret requests that are done with, and their values with them')]
class PruneSecretRequests extends Command
{
    public function handle(Pruner $pruner): int
    {
        $removed = $pruner->handle();

        $this->info($removed === 0
            ? __('console.nothing_to_prune')
            : trans_choice('console.secrets_pruned', $removed));

        return self::SUCCESS;
    }
}
