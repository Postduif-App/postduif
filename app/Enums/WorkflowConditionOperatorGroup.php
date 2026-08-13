<?php

namespace App\Enums;

/**
 * The four kinds of question a condition can ask.
 *
 * Only ever a heading in the operator dropdown — nothing is stored under these
 * names and nothing branches on them, which is why they are here rather than a
 * property on the operator itself. What they buy is that somebody choosing
 * "Is groter dan" has been told, before they choose it, that this compares
 * quantities: the single most common way to write a condition that never holds
 * is to compare two numbers as though they were words.
 */
enum WorkflowConditionOperatorGroup: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Presence = 'presence';

    public function label(): string
    {
        return match ($this) {
            self::Text => __('enums.workflow-condition-operator-group.label.Text'),
            self::Number => __('enums.workflow-condition-operator-group.label.Number'),
            self::Date => __('enums.workflow-condition-operator-group.label.Date'),
            self::Presence => __('enums.workflow-condition-operator-group.label.Presence'),
        };
    }
}
