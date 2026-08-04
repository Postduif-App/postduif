<?php

namespace App\Enums;

/**
 * What became of one step in one run.
 *
 * Skipped is not a flavour of succeeded. A step whose condition said no did
 * nothing on purpose, and folding the two together would leave the run screen
 * unable to answer the only question anybody brings to it: nothing happened —
 * was that the condition, or was it broken?
 */
enum WorkflowStepStatus: string
{
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => __('enums.workflow-step-status.label.Succeeded'),
            self::Skipped => __('enums.workflow-step-status.label.Skipped'),
            self::Failed => __('enums.workflow-step-status.label.Failed'),
        };
    }
}
