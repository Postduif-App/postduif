<?php

namespace App\Enums;

/**
 * What happens to the run when a condition says no.
 *
 * Skip is the older of the two and stays the default: a condition that was
 * written before this choice existed meant "not this step", and reading it as
 * "not the rest of the workflow" would change what somebody's workflow does
 * without them touching it.
 *
 * Stop is the one people reach for without knowing they want it — "alleen
 * verdergaan als het bericht van een gast komt" is a filter, and expressing it
 * with Skip means repeating the same condition on every step below.
 */
enum WorkflowConditionOutcome: string
{
    case Skip = 'skip';
    case Stop = 'stop';

    public function label(): string
    {
        return match ($this) {
            self::Skip => __('enums.workflow-condition-outcome.label.Skip'),
            self::Stop => __('enums.workflow-condition-outcome.label.Stop'),
        };
    }

    /**
     * The list a screen offers, as value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
