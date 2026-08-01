<?php

namespace App\Console\Commands;

use App\Actions\Chat\DispatchScheduledMessages as Dispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:dispatch-scheduled')]
#[Description('Post the messages whose scheduled moment has arrived')]
class DispatchScheduledMessages extends Command
{
    public function handle(Dispatcher $dispatcher): int
    {
        ['sent' => $sent, 'failed' => $failed] = $dispatcher->handle();

        if ($sent === 0 && $failed === 0) {
            $this->info('Niets staat klaar.');

            return self::SUCCESS;
        }

        $this->info($sent === 1 ? '1 bericht verstuurd.' : $sent.' berichten verstuurd.');

        if ($failed > 0) {
            $this->warn($failed === 1
                ? '1 bericht kon niet verstuurd worden.'
                : $failed.' berichten konden niet verstuurd worden.');
        }

        return self::SUCCESS;
    }
}
