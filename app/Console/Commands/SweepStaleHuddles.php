<?php

namespace App\Console\Commands;

use App\Actions\Huddles\SweepStaleHuddles as Sweeper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('huddles:sweep')]
#[Description('Close huddles whose people have stopped saying they are there')]
class SweepStaleHuddles extends Command
{
    public function handle(Sweeper $sweeper): int
    {
        $closed = $sweeper->handle();

        $this->info($closed === 0
            ? __('console.no_stale_huddles')
            : trans_choice('console.huddles_swept', $closed));

        return self::SUCCESS;
    }
}
