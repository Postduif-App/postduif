<?php

namespace App\Enums;

/**
 * How the rules in one condition are read together.
 *
 * Two, and no nesting. A condition that can hold a group inside a group is a
 * condition nobody can read back on a form — and the shape people actually want
 * is "all of these" or "any of these". Somebody who genuinely needs
 * (a and b) or c can say it with two steps, each guarded, and that version is
 * legible on the builder screen in a way a tree of brackets never is.
 */
enum WorkflowConditionMatch: string
{
    case All = 'all';
    case Any = 'any';

    public function label(): string
    {
        return match ($this) {
            self::All => __('enums.workflow-condition-match.label.All'),
            self::Any => __('enums.workflow-condition-match.label.Any'),
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
