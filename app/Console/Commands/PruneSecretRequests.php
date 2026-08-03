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
            ? 'Niets om op te ruimen.'
            : ($removed === 1 ? '1 verzoek opgeruimd.' : $removed.' verzoeken opgeruimd.'));

        return self::SUCCESS;
    }
}
