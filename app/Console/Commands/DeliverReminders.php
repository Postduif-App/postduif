<?php

namespace App\Console\Commands;

use App\Actions\Chat\DeliverDueReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('chat:deliver-reminders')]
#[Description('Put the reminders whose moment has arrived into their inboxes')]
class DeliverReminders extends Command
{
    public function handle(DeliverDueReminders $reminders): int
    {
        ['delivered' => $delivered, 'dropped' => $dropped] = $reminders->handle();

        if ($delivered === 0 && $dropped === 0) {
            $this->info('Niets staat klaar.');

            return self::SUCCESS;
        }

        $this->info($delivered === 1
            ? '1 herinnering bezorgd.'
            : $delivered.' herinneringen bezorgd.');

        /*
         * Said out loud rather than counted silently. A dropped reminder is one
         * whose message somebody can no longer reach, and a run that quietly
         * threw away half of them is the sort of thing worth seeing in a log.
         */
        if ($dropped > 0) {
            $this->warn($dropped === 1
                ? '1 herinnering verviel: het bericht is niet meer bereikbaar.'
                : $dropped.' herinneringen vervielen: die berichten zijn niet meer bereikbaar.');
        }

        return self::SUCCESS;
    }
}
