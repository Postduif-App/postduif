<?php

namespace App\Enums;

/**
 * Whether a step does something or decides something.
 *
 * Three, and only the first of them does anything. An action is the ordinary
 * case: it sends, adds, archives. A fork does nothing at all — it reads its
 * condition and hands the run to one of its two lanes, which is the one thing a
 * row of steps with a guard on each cannot express.
 *
 * A loop is the fork's other half: it does nothing either, and hands the run
 * its one lane once per row of a list. Which is the other thing a row of steps
 * cannot express — "doe dit voor elk van deze" had no shape at all before it,
 * and every case of it had to be built as a trigger that fired per row.
 *
 * Both of the second two keep their children in the same place a fork does, so
 * the storage, the request and the counting never learned a third idea of what
 * hangs under a step: a loop's body is its `then` lane, and there is no `else`.
 */
enum WorkflowStepKind: string
{
    case Action = 'action';
    case Branch = 'branch';
    case Loop = 'loop';

    public function label(): string
    {
        return match ($this) {
            self::Action => __('enums.workflow-step-kind.label.Action'),
            self::Branch => __('enums.workflow-step-kind.label.Branch'),
            self::Loop => __('enums.workflow-step-kind.label.Loop'),
        };
    }

    /** Whether this kind runs an action of its own, or only arranges others. */
    public function isAction(): bool
    {
        return $this === self::Action;
    }
}
