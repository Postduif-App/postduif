<?php

namespace App\Workflows\Triggers;

use App\Workflows\WorkflowField;
use App\Workflows\WorkflowTrigger;

/**
 * At a time somebody picked.
 *
 * The trigger with the least to hand over — a moment, and that is all — which
 * is why workflows behind it lean on the words written into their steps rather
 * than on variables.
 */
class ScheduleTrigger extends WorkflowTrigger
{
    public static function label(): string
    {
        return __('workflows.triggers.schedule.label');
    }

    public static function description(): string
    {
        return __('workflows.triggers.schedule.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            /*
             * Three rhythms and no cron expression. A field that accepts
             * "0 9 * * 1-5" is a field that gets it wrong silently, and the
             * mistake only shows up as a workflow that never ran — a week
             * later, if anybody notices at all.
             */
            WorkflowField::choice(
                'cadence',
                __('workflows.triggers.schedule.cadence.label'),
                [
                    'hourly' => __('workflows.triggers.schedule.cadence.hourly'),
                    'daily' => __('workflows.triggers.schedule.cadence.daily'),
                    'weekly' => __('workflows.triggers.schedule.cadence.weekly'),
                ],
            ),

            /*
             * A wall clock in the workspace's own zone, as with the status
             * rules: somebody who says nine o'clock means the reading on their
             * own clock, not an instant in UTC.
             */
            WorkflowField::text(
                'time',
                __('workflows.triggers.schedule.time.label'),
                __('workflows.triggers.schedule.time.hint'),
                required: false,
            ),

            WorkflowField::choice(
                'weekday',
                __('workflows.triggers.schedule.weekday.label'),
                [
                    '1' => __('workflows.weekdays.1'),
                    '2' => __('workflows.weekdays.2'),
                    '3' => __('workflows.weekdays.3'),
                    '4' => __('workflows.weekdays.4'),
                    '5' => __('workflows.weekdays.5'),
                    '6' => __('workflows.weekdays.6'),
                    '7' => __('workflows.weekdays.7'),
                ],
                __('workflows.triggers.schedule.weekday.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'moment.date' => __('workflows.provides.moment.date'),
            'moment.time' => __('workflows.provides.moment.time'),
        ];
    }
}
