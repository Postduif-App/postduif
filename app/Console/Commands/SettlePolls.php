<?php

namespace App\Console\Commands;

use App\Actions\Polls\SettlePolls as Settler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('polls:settle')]
#[Description('Announce the polls whose own deadline has passed, so workflows hear about them')]
class SettlePolls extends Command
{
    public function handle(Settler $settler): int
    {
        $announced = $settler->handle();

        $this->info(
            $announced === 0
                ? __('console.polls_nothing_settled')
                : trans_choice('console.polls_settled', $announced),
        );

        return self::SUCCESS;
    }
}
