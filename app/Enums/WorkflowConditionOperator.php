<?php

namespace App\Enums;

/**
 * How a step's condition compares the two sides.
 *
 * Six, and that is meant to be the whole list. A condition language that can
 * express anything is a language nobody can fill in on a form — and every
 * operator here has to be a line in a dropdown that a beheerder reads once and
 * understands. "Groter dan" is missing on purpose: almost nothing in a trigger
 * is a number, and the one thing that looks like one (an id) is not a quantity.
 */
enum WorkflowConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not-equals';
    case Contains = 'contains';
    case NotContains = 'not-contains';
    case IsEmpty = 'is-empty';
    case IsNotEmpty = 'is-not-empty';

    /**
     * Whether this operator has a right-hand side at all.
     *
     * The two emptiness ones do not, and the builder hides the value field for
     * them — a box that is ignored is a box somebody fills in and then wonders
     * about.
     */
    public function needsValue(): bool
    {
        return $this !== self::IsEmpty && $this !== self::IsNotEmpty;
    }

    public function label(): string
    {
        return match ($this) {
            self::Equals => __('enums.workflow-condition-operator.label.Equals'),
            self::NotEquals => __('enums.workflow-condition-operator.label.NotEquals'),
            self::Contains => __('enums.workflow-condition-operator.label.Contains'),
            self::NotContains => __('enums.workflow-condition-operator.label.NotContains'),
            self::IsEmpty => __('enums.workflow-condition-operator.label.IsEmpty'),
            self::IsNotEmpty => __('enums.workflow-condition-operator.label.IsNotEmpty'),
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
