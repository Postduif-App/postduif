<?php

namespace App\Console\Commands;

use App\Actions\Chat\DispatchScheduledBroadcasts as Dispatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:dispatch-broadcasts')]
#[Description('Post the announcements whose scheduled moment has arrived')]
class DispatchScheduledBroadcasts extends Command
{
    public function handle(Dispatcher $dispatcher): int
    {
        ['sent' => $sent, 'failed' => $failed] = $dispatcher->handle();

        if ($sent === 0 && $failed === 0) {
            $this->info(__('console.broadcasts_none'));

            return self::SUCCESS;
        }

        $this->info(trans_choice('console.broadcasts_sent', $sent));

        if ($failed > 0) {
            $this->warn(trans_choice('console.broadcasts_failed', $failed));
        }

        return self::SUCCESS;
    }
}
