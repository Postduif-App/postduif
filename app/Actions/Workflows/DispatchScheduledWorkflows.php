<?php

namespace App\Actions\Workflows;

use App\Models\Workflow;
use App\Workflows\Triggers\ScheduleTrigger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Set off the workflows whose moment has come.
 *
 * Swept every minute, like the scheduled messages and the status rules, and for
 * the same reason: somebody who says nine o'clock means nine o'clock, and a
 * coarser interval would turn the time they picked into a rough indication.
 *
 * The clock is the owner's, not the server's. Somebody in Amsterdam who says
 * nine means the reading on their own clock — see User::localNow(), which the
 * status rules already lean on for exactly this. The workspace has no timezone
 * of its own, and the owner's is the honest stand-in: they are the person who
 * chose the time.
 */
class DispatchScheduledWorkflows
{
    public function __construct(
        private readonly StartWorkflow $startWorkflow,
    ) {}

    /**
     * @return int How many workflows were set off.
     */
    public function handle(): int
    {
        $started = 0;

        foreach ($this->candidates() as $workflow) {
            $owner = $workflow->owner;

            // No owner, no clock, and nothing the runner would accept anyway.
            if ($owner === null) {
                continue;
            }

            $localNow = $owner->localNow();

            if (! $this->isDue($workflow, $localNow)) {
                continue;
            }

            /*
             * Stamped before it is started, and only when this call managed to
             * stamp it. That is what keeps one moment from firing twice: a
             * second sweep starting while this one is halfway finds a row that
             * already says it has had its turn.
             *
             * The cost runs the other way — a crash between the stamp and the
             * dispatch loses that occurrence rather than repeating it. That is
             * the safer of the two: a message posted twice cannot be taken
             * back, and a daily workflow that missed one morning runs again
             * tomorrow.
             */
            if (! $this->claim($workflow)) {
                continue;
            }

            $this->startWorkflow->handle($workflow, [
                'moment' => [
                    'date' => $localNow->toDateString(),
                    'time' => $localNow->format('H:i'),
                ],
            ]);

            $started++;
        }

        return $started;
    }

    /**
     * Every switched-on scheduled workflow there is.
     *
     * Not scoped to a workspace — the scheduler has no workspace, it has a
     * clock — which is why the index this leans on is on (trigger_type,
     * enabled_at) rather than starting at workspace_id like every other query
     * on this table.
     *
     * @return Collection<int, Workflow>
     */
    private function candidates()
    {
        return Workflow::query()
            ->where('trigger_type', ScheduleTrigger::key())
            ->whereNotNull('enabled_at')
            ->with(['owner', 'workspace'])
            ->get();
    }

    /**
     * Whether this is the minute.
     *
     * Everything here is a wall clock comparison and nothing converts, which is
     * the whole point and settles the daylight saving question without a
     * special case: on the night the clocks go forward a workflow set for 02:30
     * never fires, because no clock in that zone ever reads 02:30 that night.
     */
    private function isDue(Workflow $workflow, CarbonInterface $localNow): bool
    {
        [$hour, $minute] = $this->wanted($workflow);

        if ($localNow->minute !== $minute) {
            return false;
        }

        $cadence = (string) $workflow->triggerSetting('cadence', 'daily');

        if ($cadence === 'hourly') {
            return $this->notYetThisHour($workflow, $localNow);
        }

        if ($localNow->hour !== $hour) {
            return false;
        }

        if ($cadence === 'weekly'
            && $localNow->dayOfWeekIso !== (int) $workflow->triggerSetting('weekday', 1)) {
            return false;
        }

        return $this->notYetToday($workflow, $localNow);
    }

    /**
     * The hour and minute somebody asked for.
     *
     * Nine in the morning when they said nothing, which is a guess but a
     * defensible one: it is the time of day a workspace notices things. An
     * unparseable value falls back to the same rather than throwing — a
     * workflow that never runs is easier to notice than a scheduler that
     * stopped.
     *
     * @return array{0: int, 1: int}
     */
    private function wanted(Workflow $workflow): array
    {
        $time = (string) $workflow->triggerSetting('time', '');

        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $found) !== 1) {
            return [9, 0];
        }

        $hour = (int) $found[1];
        $minute = (int) $found[2];

        if ($hour > 23 || $minute > 59) {
            return [9, 0];
        }

        return [$hour, $minute];
    }

    private function notYetThisHour(Workflow $workflow, CarbonInterface $localNow): bool
    {
        $last = $workflow->schedule_ran_at;

        return $last === null || $last->setTimezone($localNow->timezone)->format('Y-m-d H') !== $localNow->format('Y-m-d H');
    }

    private function notYetToday(Workflow $workflow, CarbonInterface $localNow): bool
    {
        $last = $workflow->schedule_ran_at;

        return $last === null || $last->setTimezone($localNow->timezone)->toDateString() !== $localNow->toDateString();
    }

    /**
     * Stamp the row, and say whether this call was the one that did it.
     *
     * The where on the previous value is what makes it a claim rather than a
     * write: two sweeps racing both send an update, and only one of them
     * touches a row.
     */
    private function claim(Workflow $workflow): bool
    {
        return DB::transaction(fn (): bool => Workflow::query()
            ->whereKey($workflow->id)
            ->where(function ($query) use ($workflow) {
                $workflow->schedule_ran_at === null
                    ? $query->whereNull('schedule_ran_at')
                    : $query->where('schedule_ran_at', $workflow->schedule_ran_at);
            })
            ->update(['schedule_ran_at' => now()]) === 1);
    }
}
