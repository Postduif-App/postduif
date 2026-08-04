<?php

namespace App\Console\Commands;

use App\Actions\Workflows\DispatchScheduledWorkflows as Dispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:dispatch-scheduled')]
#[Description('Set off the workflows whose moment has come')]
class DispatchScheduledWorkflows extends Command
{
    public function handle(Dispatcher $dispatcher): int
    {
        $started = $dispatcher->handle();

        $this->info($started === 0
            ? __('console.workflows_none_due')
            : trans_choice('console.workflows_started', $started));

        return self::SUCCESS;
    }
}
