<?php

namespace App\Workflows\Actions;

use App\Enums\WorkflowAwaitableEvent;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowAwaits;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use RuntimeException;

/**
 * Wait until something happens, or until the time is up.
 *
 * The step Delay could not be. "Wacht drie dagen en herinner dan wie nog niet
 * getekend heeft" was writable; "wacht tot dit contract getekend is, en meld
 * het als dat na drie dagen nog niet zo is" was not — it needed two workflows
 * that had to find each other by a detour, and the first of them acted three
 * days late even when everything went well.
 *
 * What it leaves behind is one word: whether it happened.
 *
 *     Wacht op: contract getekend, hoogstens 3 dagen
 *     Als {{ steps.0.happened }} is waar  → bedank de ondertekenaar
 *     anders                              → meld dat er niemand getekend heeft
 *
 * Two lanes, and no new kind of step to get them: the fork underneath is the
 * one the builder already has. Writing this as a step with two lanes of its own
 * would have been a second thing that branches, a second set of nesting rules
 * and a second place for the builder to draw a fork — for a question a
 * condition answers. The same reasoning PollTrigger gives about thresholds: the
 * step produces the fact, the condition compares it.
 *
 * The deadline is not optional. A run waiting for something that never comes is
 * a row nobody ever looks at again, and it would hold the workflow's remaining
 * steps forever — so waiting always ends, and `happened` says which way.
 */
class WaitForEvent extends WorkflowAction
{
    /**
     * The same four weeks a Delay may wait.
     *
     * Deliberately the same number rather than a longer one for this step: an
     * await is a delay with a shortcut, and two different ceilings would be two
     * answers to "how long may a workflow hold its breath".
     */
    public const MAX_MINUTES = Delay::MAX_MINUTES;

    public static function label(): string
    {
        return __('workflows.actions.wait-for-event.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.wait-for-event.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::choice(
                'event',
                __('workflows.actions.wait-for-event.event.label'),
                WorkflowAwaitableEvent::options(),
                __('workflows.actions.wait-for-event.event.hint'),
            ),
            WorkflowField::number(
                'minutes',
                __('workflows.actions.wait-for-event.minutes.label'),
                __('workflows.actions.wait-for-event.minutes.hint'),
            ),
            /*
             * Which record, for the rare case where it is not the obvious one.
             *
             * Empty means the record this workflow was set off by, which is what
             * nearly every wait means and needs nothing typed. A variable is for
             * the other case: "wacht tot het contract dat stap twee verstuurde
             * getekend is" names something that did not exist when the run
             * started, so no picker could hold it.
             */
            WorkflowField::text(
                'record_id',
                __('workflows.actions.wait-for-event.record.label'),
                __('workflows.actions.wait-for-event.record.hint'),
                required: false,
            ),
        ];
    }

    /** @return array<string, string> */
    public static function provides(): array
    {
        return [
            'happened' => __('workflows.provides.await.happened'),
            'event' => __('workflows.provides.await.event'),
            'record' => __('workflows.provides.await.record'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        $event = WorkflowAwaitableEvent::tryFrom((string) $context->setting('event'));

        if ($event === null) {
            throw new RuntimeException(__('workflows.errors.no_event_chosen'));
        }

        $minutes = (int) $context->setting('minutes', 0);

        /*
         * Nought is not a wait, and the same refusal Delay makes: a step that
         * reads as waiting and does not is worse than one that says so.
         */
        if ($minutes < 1) {
            throw new RuntimeException(__('workflows.errors.delay_too_short'));
        }

        if ($minutes > self::MAX_MINUTES) {
            throw new RuntimeException(__('workflows.errors.delay_too_long'));
        }

        $record = $context->setting('record_id');

        if (blank($record)) {
            $record = $context->value($event->record()->triggerPath());
        }

        /*
         * Nothing to wait about. Refused rather than turned into a plain delay,
         * which is what it would silently become: a workflow that says "wacht
         * tot dit getekend is" and quietly waits three days instead is wrong in
         * a way nobody would ever catch reading it.
         */
        if (blank($record)) {
            throw new RuntimeException(__('workflows.errors.no_record_to_await', [
                'what' => $event->record()->label(),
            ]));
        }

        throw new WorkflowAwaits($event, (string) $record, now()->addMinutes($minutes));
    }
}
