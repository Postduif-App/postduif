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
 * Every minute. An appointment at two o'clock announced at ten past two is one
 * people have already given up on — the same reasoning as the scheduled
 * messages above, and the reason both run at this interval rather than a
 * cheaper one.
 */
Schedule::command('huddles:announce-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * Every minute, for the same reason as the scheduled messages above: somebody
 * who asks to be reminded at nine means nine, and a coarser interval would turn
 * the moment they picked into a rough indication.
 *
 * withoutOverlapping beside the claim in the sweep itself. The sweep already
 * refuses to deliver a reminder twice — it fills delivered_at before it writes
 * anything — so this is the cheap outer guard rather than the correctness one.
 */
Schedule::command('chat:deliver-reminders')
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

/**
 * Daily, in the small hours. Nothing here is urgent — a transfer that expired
 * this afternoon may perfectly well be cleared tonight — and this is the one
 * scheduled job that deletes files, so it deserves a moment when nobody is
 * downloading.
 *
 * withoutOverlapping because a run over a large backlog can take a while, and
 * two of them deleting the same rows would have the second one working through
 * a list that is disappearing under it.
 */
Schedule::command('transfers:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping();

/**
 * Daily, an hour before the transfers are cleared. Same reasoning, shorter
 * fuse: what is being deleted here is other people's passwords, and the
 * grace period is measured in days rather than a week for that reason.
 */
Schedule::command('secrets:prune')
    ->dailyAt('02:30')
    ->withoutOverlapping();

/**
 * Daily, after the rest of the night's clearing up. Nothing downstream waits on
 * it and nobody is inconvenienced by it running late, so it goes last.
 *
 * withoutOverlapping for the same reason as the others: a first run working
 * through a long backlog should not have a second one deleting rows out from
 * under it.
 */
Schedule::command('inbox:prune')
    ->dailyAt('04:30')
    ->withoutOverlapping();

/**
 * Daily, half an hour after the secrets and an hour before the transfers.
 *
 * Two jobs in one command — see PruneContracts — and the first of them is the
 * reason it does not go with the rest of the late-night clearing up: marking a
 * contract expired is what the overview reads to say "verlopen", and a
 * workspace that starts at nine should not spend the morning being told a
 * deadline that passed at midnight is still running.
 *
 * withoutOverlapping because a run over a long backlog deletes files one row at
 * a time, and a second one would be working through a list disappearing under
 * it.
 */
Schedule::command('contracts:prune')
    ->dailyAt('03:00')
    ->withoutOverlapping();

/**
 * Daily, and it removes the least in the fewest runs — a document has to have
 * been in the bin for a month before this touches it.
 *
 * Deleting a document is soft on purpose: it is the one thing in a channel that
 * took months and exists nowhere else. This is what keeps "soft" from meaning
 * "forever", and it is also the only thing that ever hands back the disk space
 * of the pictures inside a document nobody kept.
 */
Schedule::command('documents:prune')
    ->dailyAt('04:40')
    ->withoutOverlapping();

/**
 * Every minute, beside the scheduled messages and for the same reason: a
 * moment somebody picked should not arrive noticeably late.
 *
 * withoutOverlapping because claiming and sending are two steps — see
 * DispatchScheduledBroadcasts::claimDue, which stamps the row before posting so
 * an announcement cannot go out twice.
 */
Schedule::command('chat:dispatch-broadcasts')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * Every minute, beside the scheduled messages: a workflow that was told to wait
 * an hour should not come back noticeably later than that, and the wait is the
 * one part of a workflow whose timing somebody explicitly chose.
 *
 * Cheap when there is nothing to do — one indexed query on (status, resume_at).
 *
 * withoutOverlapping is the belt beside the braces: the sweep claims its runs
 * inside a transaction before dispatching them, so a second one finds nothing
 * to pick up twice either way.
 */
Schedule::command('workflows:resume')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * Every minute, beside the scheduled messages and the status rules. Somebody
 * who says nine o'clock means nine o'clock, and a coarser interval would turn
 * the time they picked into a rough indication.
 *
 * Cheap when there is nothing to do: only the switched-on scheduled workflows
 * are looked at, and for almost all of them the answer is "not this minute".
 *
 * withoutOverlapping is the belt beside the braces here too — the dispatcher
 * stamps each workflow before starting it, so one moment cannot fire twice
 * either way.
 */
Schedule::command('workflows:dispatch-scheduled')
    ->everyMinute()
    ->withoutOverlapping();

/**
 * Daily, after the night's other clearing up. A workflow run holds the context
 * as it stood — message text, people's names — so it is a debugging aid with a
 * shelf life rather than a record worth keeping, the same reasoning as the
 * transfers and the secrets.
 *
 * Nothing waits on it and nobody is inconvenienced by it running late, so it
 * goes last.
 */
Schedule::command('workflows:prune-runs')
    ->dailyAt('04:45')
    ->withoutOverlapping();

/**
 * And the receipts people were shown once. Most of them are already past their
 * moment within ten minutes; this is what clears the ones that waited to be
 * dismissed and never were. Beside the runs above, at the same quiet hour.
 */
/**
 * Every minute, because a channel holds one live huddle at a time: a huddle
 * left standing by a browser that crashed blocks that channel until this runs,
 * and "wacht even een kwartier" is not an answer somebody accepts about a
 * button that does nothing.
 */
Schedule::command('huddles:sweep')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('chat:prune-notices')
    ->dailyAt('04:50')
    ->withoutOverlapping();
