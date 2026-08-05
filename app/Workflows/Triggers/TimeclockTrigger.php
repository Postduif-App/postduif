<?php

namespace App\Workflows\Triggers;

use App\Features\Timeclock;
use App\Models\Workflow;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * Somebody clocked in, or clocked out.
 *
 * What this is reached for: a message in the ploegleider's channel when the
 * first person arrives, a reminder to whoever is still clocked in at six, a
 * line in a log when a shift that ran too long is finally closed.
 *
 * One trigger with a direction rather than two triggers, because the two are
 * the same event pointed the other way and a picker with "inklokken" and
 * "uitklokken" next to each other would make somebody choose twice for a
 * workflow that wants both.
 */
class TimeclockTrigger extends WorkflowTrigger
{
    /** What a workflow may say it is waiting for. */
    public const DIRECTIONS = ['both', 'in', 'out'];

    public static function label(): string
    {
        return __('workflows.triggers.timeclock.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.timeclock.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            /*
             * Required, with "beide" as a real answer rather than as the empty
             * one. A blank meaning "everything" reads as a field somebody
             * forgot to fill in, and this is the one setting that decides
             * whether a workflow runs twice a day or once.
             */
            WorkflowField::choice(
                'direction',
                __('workflows.triggers.timeclock.direction.label'),
                [
                    'both' => __('workflows.triggers.timeclock.direction.both'),
                    'in' => __('workflows.triggers.timeclock.direction.in'),
                    'out' => __('workflows.triggers.timeclock.direction.out'),
                ],
                __('workflows.triggers.timeclock.direction.hint'),
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
            'punch.direction' => __('workflows.provides.punch.direction'),
            'punch.at' => __('workflows.provides.punch.at'),
            /*
             * How long the shift lasted, for a workflow that fires on clocking
             * out. Zero on the way in, which is the honest answer: the shift
             * has just begun and nothing has been worked yet.
             */
            'shift.hours' => __('workflows.provides.shift.hours'),
            'shift.duration' => __('workflows.provides.shift.duration'),
            'shift.started_at' => __('workflows.provides.shift.started_at'),
        ];
    }

    /**
     * Only where the workspace keeps a clock at all.
     *
     * The same answer the webhook and form triggers give, for the same reason:
     * a trigger that can never fire is worse than one that is not offered.
     */
    public static function availableFor(Workflow $workflow): bool
    {
        return $workflow->workspace?->hasFeature(Timeclock::class) ?? false;
    }
}
