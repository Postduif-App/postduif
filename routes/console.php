<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Every quarter of an hour, which is the resolution the shortest threshold on
 * offer (half an hour) needs to be honoured within reason. Without overlapping,
 * because a long run must not start a second one that would report the same
 * messages before the first has moved its pointers.
 */
Schedule::command('chat:notify-absent')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/**
 * Hourly is fine: the thresholds are measured in hours and a ticket that has
 * been sitting for a day is not more urgent forty minutes from now. What keeps
 * this from nagging is the cooldown on the ticket itself, not the interval.
 */
Schedule::command('tickets:notify-stale')
    ->hourly()
    ->withoutOverlapping();

/**
 * Every minute: somebody who schedules a message for 09:00 means 09:00, and a
 * coarser interval would make the time they picked a suggestion. Cheap enough —
 * with nothing due it is one indexed query.
 *
 * withoutOverlapping is a belt beside the braces: the dispatcher claims rows
 * before it posts them, so a second run finds nothing to repeat either way.
 */
Schedule::command('chat:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * Every minute. Somebody who says their status changes at nine means nine, and
 * a coarser interval would turn the time they picked into a rough indication —
 * the same reasoning as the scheduled messages above.
 *
 * Cheap when there is nothing to do: only members who actually have rules are
 * looked at, and for each of them the answer is usually "already right".
 */
Schedule::command('users:apply-status-rules')
    ->everyMinute()
    ->withoutOverlapping();
