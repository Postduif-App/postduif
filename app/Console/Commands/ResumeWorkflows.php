<?php

namespace App\Console\Commands;

use App\Actions\Workflows\ResumeWaitingWorkflows;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('workflows:resume')]
#[Description('Pick up the workflow runs whose waiting is over')]
class ResumeWorkflows extends Command
{
    public function handle(ResumeWaitingWorkflows $resume): int
    {
        $resumed = $resume->handle();

        $this->info($resumed === 0
            ? __('console.workflows_none_waiting')
            : trans_choice('console.workflows_resumed', $resumed));

        return self::SUCCESS;
    }
}
