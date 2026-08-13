<?php

namespace App\Workflows\Actions;

use App\Actions\Timeclock\ClockOut as EndShift;
use App\Features\Timeclock;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Take somebody off the clock.
 *
 * For the shift nobody closed: the laptop that was shut at five with the button
 * untouched, and the entry that then runs all night and turns up in the week as
 * sixteen hours. On the schedule trigger this is "sluit om middernacht wat nog
 * open staat", said once.
 *
 * The end time is now, not a tidied-up hour. Inventing six o'clock because that
 * is when people usually leave would be the application writing down a moment
 * nobody was present for — see ClockOut, which makes the same choice and leaves
 * the correcting to whoever was there.
 *
 * Nothing running is not a failure. Somebody who already clocked out is in the
 * state this step wanted, and a workflow that ran on a quiet Tuesday should not
 * be reported as broken for finding nothing to do.
 */
class ClockOut extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly EndShift $endShift) {}

    public static function label(): string
    {
        return __('workflows.actions.clock-out.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.clock-out.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            /*
             * Optional, and an empty box is the interesting answer: the person
             * the trigger was about. That is what makes this usable from the
             * timeclock trigger, where the member is different every time and a
             * picker could never name them.
             */
            WorkflowField::member(
                'user_id',
                __('workflows.actions.clock-out.person.label'),
                __('workflows.actions.clock-out.person.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'user.id' => __('workflows.provides.user.id'),
            'user.name' => __('workflows.provides.user.name'),
            'shift.hours' => __('workflows.provides.shift.hours'),
            'shift.was_running' => __('workflows.provides.shift.was_running'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Timeclock::class)) {
            throw new RuntimeException(__('workflows.errors.timeclock_off'));
        }

        $member = $this->memberOrTriggerUser($context);

        $entry = $this->endShift->handle($member, $context->workspace());

        return [
            'user' => ['id' => $member->id, 'name' => $member->name],
            'shift' => [
                // Nought when there was nothing running, which is an ordinary
                // answer and not a failure.
                'hours' => $entry === null ? 0 : round($entry->seconds() / 3600, 1),
                'was_running' => $entry !== null,
            ],
        ];
    }
}
