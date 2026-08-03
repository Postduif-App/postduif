<?php

namespace App\Console\Commands;

use App\Actions\Transfers\PruneTransfers as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transfers:prune')]
#[Description('Remove transfers that have been finished long enough, and their files with them')]
class PruneTransfers extends Command
{
    public function handle(Pruner $pruner): int
    {
        $removed = $pruner->handle();

        $this->info($removed === 0
            ? 'Niets om op te ruimen.'
            : ($removed === 1 ? '1 verzending opgeruimd.' : $removed.' verzendingen opgeruimd.'));

        return self::SUCCESS;
    }
}
