<?php

namespace App\Listeners;

use App\Events\ClockPunched;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Workflows\Triggers\TimeclockTrigger;

/**
 * Set off the workflows that were waiting for somebody to clock.
 *
 * The direction is filtered in PHP rather than in the query, the same way the
 * channel is on a join: it is one setting on a handful of workflows, and a
 * where on a JSON column to save reading three rows is a trade nobody comes out
 * ahead on.
 *
 * @extends StartsWorkflows<ClockPunched>
 */
class StartTimeclockWorkflows extends StartsWorkflows
{
    public function handle(ClockPunched $event): void
    {
        $this->start($event);
    }

    protected function trigger(): string
    {
        return TimeclockTrigger::class;
    }

    /**
     * @param  ClockPunched  $event
     */
    protected function workspaceOf(object $event): ?Workspace
    {
        return $event->workspace;
    }

    /**
     * What a step may reach for. The contract is TimeclockTrigger::provides(),
     * and the two have to be read together — a path offered there and missing
     * here is a variable that renders as nothing.
     *
     * @param  ClockPunched  $event
     * @return array<string, mixed>|null
     */
    protected function contextFor(Workflow $workflow, object $event): ?array
    {
        $wanted = $workflow->triggerSetting('direction', 'both');

        if ($wanted !== 'both' && $wanted !== $event->direction) {
            return null;
        }

        $entry = $event->entry;
        $minutes = intdiv($entry->seconds(), 60);

        return [
            'user' => ['id' => $event->user->id, 'name' => $event->user->name],
            'punch' => [
                'direction' => $event->direction,
                // On the member's own clock, because it is going into a
                // sentence a person reads about their own morning.
                'at' => $entry->onMemberClock(
                    $event->direction === 'in' ? $entry->started_at : ($entry->ended_at ?? $entry->started_at),
                    $event->user,
                )->format('H:i'),
            ],
            'shift' => [
                // One decimal: a workflow that compares against eight should
                // not have to reason about seconds, and "7,5" is how a working
                // day is talked about anyway.
                'hours' => round($minutes / 60, 1),
                'duration' => __('timeclock.spoken_duration', [
                    'hours' => intdiv($minutes, 60),
                    'minutes' => $minutes % 60,
                ]),
                'started_at' => $entry->onMemberClock($entry->started_at, $event->user)->format('H:i'),
            ],
        ];
    }
}
