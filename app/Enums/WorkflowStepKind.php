<?php

namespace App\Enums;

/**
 * Whether a step does something or decides something.
 *
 * Two, and the second exists so that a workflow can be a shape. An action is
 * the ordinary case: it sends, adds, archives. A fork does nothing at all — it
 * reads its condition and hands the run to one of its two lanes, which is the
 * one thing a row of steps with a guard on each cannot express.
 */
enum WorkflowStepKind: string
{
    case Action = 'action';
    case Branch = 'branch';

    public function label(): string
    {
        return match ($this) {
            self::Action => __('enums.workflow-step-kind.label.Action'),
            self::Branch => __('enums.workflow-step-kind.label.Branch'),
        };
    }
}
