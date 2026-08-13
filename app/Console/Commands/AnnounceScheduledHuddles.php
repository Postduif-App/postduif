<?php

namespace App\Console\Commands;

use App\Actions\Huddles\AnnounceScheduledHuddles as Announcer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('huddles:announce-scheduled')]
#[Description('Tell the channel about the huddles whose moment has arrived')]
class AnnounceScheduledHuddles extends Command
{
    public function handle(Announcer $announcer): int
    {
        ['announced' => $announced, 'skipped' => $skipped] = $announcer->handle();

        if ($announced === 0 && $skipped === 0) {
            $this->info('Niets staat klaar.');

            return self::SUCCESS;
        }

        $this->info($announced === 1
            ? '1 huddle aangekondigd.'
            : $announced.' huddles aangekondigd.');

        /*
         * Said out loud rather than counted silently: a skipped appointment is
         * one whose channel was archived or whose workspace switched huddles
         * off since it was planned, and a run that quietly dropped half of them
         * is worth seeing in a log.
         */
        if ($skipped > 0) {
            $this->warn($skipped === 1
                ? '1 huddle verviel: het kanaal is er niet meer of huddles staan uit.'
                : $skipped.' huddles vervielen: die kanalen zijn er niet meer of huddles staan uit.');
        }

        return self::SUCCESS;
    }
}
