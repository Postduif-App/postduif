<?php

namespace App\Workflows\Actions;

use App\Actions\Timeclock\SummariseHours as AddUpHours;
use App\Features\Timeclock;
use App\Workflows\Actions\Concerns\FindsTargets;
use App\Workflows\WorkflowAction;
use App\Workflows\WorkflowField;
use App\Workflows\WorkflowStepContext;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Add up somebody's week.
 *
 * It sends nothing and posts nothing. What it does is put the numbers where the
 * next step can read them — {{ steps.0.hours.spoken }} in a message, in a
 * document, in a ticket — which is the difference between an action that does
 * one useful thing and an action that has to grow a channel picker, a
 * recipient, and a sentence of its own.
 *
 * The week is the member's own, in their own zone, counted the way the screen
 * counts it: a shift that began on Sunday evening belongs to the week it
 * started in. All of that is SummariseHours, and going round it here would mean
 * two answers to "hoeveel heb ik gedraaid".
 */
class SummariseHours extends WorkflowAction
{
    use FindsTargets;

    public function __construct(private readonly AddUpHours $addUp) {}

    public static function label(): string
    {
        return __('workflows.actions.summarise-hours.label');
    }

    public static function description(): string
    {
        return __('workflows.actions.summarise-hours.description');
    }

    /** @return list<WorkflowField> */
    public static function fields(): array
    {
        return [
            WorkflowField::member(
                'user_id',
                __('workflows.actions.summarise-hours.person.label'),
                __('workflows.actions.summarise-hours.person.hint'),
                required: false,
            ),
            /*
             * Last week is the one worth having and the reason this choice
             * exists: a summary sent on Monday is about the week that finished,
             * and asking somebody to reason about that with a delay step would
             * be asking them to do arithmetic in a box.
             */
            WorkflowField::choice(
                'week',
                __('workflows.actions.summarise-hours.week.label'),
                [
                    'this' => __('workflows.actions.summarise-hours.week.this'),
                    'last' => __('workflows.actions.summarise-hours.week.last'),
                ],
                __('workflows.actions.summarise-hours.week.hint'),
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
            'hours.total' => __('workflows.provides.hours.total'),
            'hours.spoken' => __('workflows.provides.hours.spoken'),
            'hours.days_worked' => __('workflows.provides.hours.days_worked'),
            'hours.from' => __('workflows.provides.hours.from'),
            'hours.until' => __('workflows.provides.hours.until'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function run(WorkflowStepContext $context): ?array
    {
        if (! $context->workspace()->hasFeature(Timeclock::class)) {
            throw new RuntimeException(__('workflows.errors.timeclock_off'));
        }

        $member = $this->memberOrTriggerUser($context);

        $week = $this->addUp->forMember(
            member: $member,
            workspace: $context->workspace(),
            anchor: $context->setting('week') === 'last' ? Carbon::now()->subWeek() : null,
        );

        $minutes = intdiv($week['seconds'], 60);

        return [
            'user' => ['id' => $member->id, 'name' => $member->name],
            'hours' => [
                // One decimal, the same as the trigger's: a condition comparing
                // against forty should not have to reason about seconds.
                'total' => round($week['seconds'] / 3600, 1),
                'spoken' => __('timeclock.spoken_duration', [
                    'hours' => intdiv($minutes, 60),
                    'minutes' => $minutes % 60,
                ]),
                // Days actually worked rather than seven, which is the number
                // somebody means by "hoeveel dagen".
                'days_worked' => count(array_filter(
                    $week['days'],
                    fn (array $day): bool => $day['seconds'] > 0,
                )),
                'from' => $week['from'],
                'until' => $week['until'],
            ],
        ];
    }
}
