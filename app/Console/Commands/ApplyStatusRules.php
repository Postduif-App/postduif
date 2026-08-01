<?php

namespace App\Console\Commands;

use App\Actions\Users\ApplyStatusRules as Applier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:apply-status-rules')]
#[Description('Set the statuses that are due, and clear the ones whose window has passed')]
class ApplyStatusRules extends Command
{
    public function handle(Applier $applier): int
    {
        ['applied' => $applied, 'cleared' => $cleared] = $applier->handle();

        if ($applied === 0 && $cleared === 0) {
            $this->info('Iedereen staat al goed.');

            return self::SUCCESS;
        }

        $this->info($applied.' gezet, '.$cleared.' opgeruimd.');

        return self::SUCCESS;
    }
}
