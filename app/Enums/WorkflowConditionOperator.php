<?php

namespace App\Enums;

/**
 * How a step's condition compares the two sides.
 *
 * This used to be six, and the comment here used to say that six was meant to
 * be the whole list — a condition language that can express anything is a
 * language nobody can fill in on a form. That reasoning still holds, and it is
 * why there is no "matches this regular expression" and no arithmetic.
 *
 * What it got wrong was the assumption underneath: that almost nothing in a
 * trigger is a number, and the one thing that looks like one is an id. That was
 * true when every trigger was about a message. It stopped being true the moment
 * triggers started carrying how many people still have to sign, how many days
 * are left before a contract expires, and how long a ticket has been open.
 * Against six string operators, "verloopt binnen drie dagen" is not an awkward
 * condition — it is an impossible one, and the workflow that needed it gets
 * written to fire on everything instead.
 *
 * So: still no arithmetic, still nothing that needs explaining twice, but each
 * of these answers a question somebody actually has. They come in four kinds —
 * see group(), which is what keeps the dropdown readable now there are twenty.
 */
enum WorkflowConditionOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not-equals';
    case Contains = 'contains';
    case NotContains = 'not-contains';
    case StartsWith = 'starts-with';
    case EndsWith = 'ends-with';
    case IsOneOf = 'is-one-of';
    case IsNoneOf = 'is-none-of';

    case GreaterThan = 'greater-than';
    case LessThan = 'less-than';
    case GreaterOrEqual = 'greater-or-equal';
    case LessOrEqual = 'less-or-equal';

    case Before = 'before';
    case After = 'after';

    case IsEmpty = 'is-empty';
    case IsNotEmpty = 'is-not-empty';
    case IsTrue = 'is-true';
    case IsFalse = 'is-false';

    /**
     * Whether this operator has a right-hand side at all.
     *
     * The four that ask about the value itself do not, and the builder hides
     * the value field for them — a box that is ignored is a box somebody fills
     * in and then wonders about.
     */
    public function needsValue(): bool
    {
        return ! in_array($this, [self::IsEmpty, self::IsNotEmpty, self::IsTrue, self::IsFalse], true);
    }

    /**
     * Which heading this operator sits under.
     *
     * Twenty lines in one dropdown is a list you scroll rather than read.
     * Grouped, it is four short lists, and the heading does half the explaining:
     * somebody who picks an operator under "Getallen" has already been told
     * that this compares as a quantity and not as text.
     */
    public function group(): WorkflowConditionOperatorGroup
    {
        return match ($this) {
            self::Equals, self::NotEquals,
            self::Contains, self::NotContains,
            self::StartsWith, self::EndsWith,
            self::IsOneOf, self::IsNoneOf => WorkflowConditionOperatorGroup::Text,

            self::GreaterThan, self::LessThan,
            self::GreaterOrEqual, self::LessOrEqual => WorkflowConditionOperatorGroup::Number,

            self::Before, self::After => WorkflowConditionOperatorGroup::Date,

            self::IsEmpty, self::IsNotEmpty,
            self::IsTrue, self::IsFalse => WorkflowConditionOperatorGroup::Presence,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Equals => __('enums.workflow-condition-operator.label.Equals'),
            self::NotEquals => __('enums.workflow-condition-operator.label.NotEquals'),
            self::Contains => __('enums.workflow-condition-operator.label.Contains'),
            self::NotContains => __('enums.workflow-condition-operator.label.NotContains'),
            self::StartsWith => __('enums.workflow-condition-operator.label.StartsWith'),
            self::EndsWith => __('enums.workflow-condition-operator.label.EndsWith'),
            self::IsOneOf => __('enums.workflow-condition-operator.label.IsOneOf'),
            self::IsNoneOf => __('enums.workflow-condition-operator.label.IsNoneOf'),
            self::GreaterThan => __('enums.workflow-condition-operator.label.GreaterThan'),
            self::LessThan => __('enums.workflow-condition-operator.label.LessThan'),
            self::GreaterOrEqual => __('enums.workflow-condition-operator.label.GreaterOrEqual'),
            self::LessOrEqual => __('enums.workflow-condition-operator.label.LessOrEqual'),
            self::Before => __('enums.workflow-condition-operator.label.Before'),
            self::After => __('enums.workflow-condition-operator.label.After'),
            self::IsEmpty => __('enums.workflow-condition-operator.label.IsEmpty'),
            self::IsNotEmpty => __('enums.workflow-condition-operator.label.IsNotEmpty'),
            self::IsTrue => __('enums.workflow-condition-operator.label.IsTrue'),
            self::IsFalse => __('enums.workflow-condition-operator.label.IsFalse'),
        };
    }

    /**
     * What to put beside the value box once this operator is chosen.
     *
     * Null for most of them, because "is gelijk aan" needs no explaining. The
     * three that do are the three where somebody would otherwise find out by
     * writing a workflow that quietly never fires: a list is separated by
     * commas, a date may be written as a variable or as a day, and comparing
     * two things that are not numbers falls back to comparing them as text.
     */
    public function hint(): ?string
    {
        $kind = match ($this) {
            self::IsOneOf, self::IsNoneOf => 'list',
            self::Before, self::After => 'date',
            self::GreaterThan, self::LessThan,
            self::GreaterOrEqual, self::LessOrEqual => 'number',
            default => null,
        };

        return $kind === null
            ? null
            : (string) __("enums.workflow-condition-operator.hint.{$kind}");
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

    /**
     * The same list under its four headings, which is how the builder draws it.
     *
     * @return list<array{group: string, label: string, operators: list<array{value: string, label: string, needsValue: bool, hint: string|null}>}>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (WorkflowConditionOperatorGroup::cases() as $group) {
            $operators = [];

            foreach (self::cases() as $case) {
                if ($case->group() !== $group) {
                    continue;
                }

                $operators[] = [
                    'value' => $case->value,
                    'label' => $case->label(),
                    'needsValue' => $case->needsValue(),
                    'hint' => $case->hint(),
                ];
            }

            $grouped[] = [
                'group' => $group->value,
                'label' => $group->label(),
                'operators' => $operators,
            ];
        }

        return $grouped;
    }
}
