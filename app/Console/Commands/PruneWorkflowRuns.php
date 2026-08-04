<?php

namespace App\Console\Commands;

use App\Actions\Workflows\PruneWorkflowRuns as Pruner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:prune-runs')]
#[Description('Remove workflow runs that have been finished long enough')]
class PruneWorkflowRuns extends Command
{
    public function handle(Pruner $pruner): int
    {
        $removed = $pruner->handle();

        $this->info($removed === 0
            ? __('console.nothing_to_prune')
            : trans_choice('console.workflow_runs_pruned', $removed));

        return self::SUCCESS;
    }
}
