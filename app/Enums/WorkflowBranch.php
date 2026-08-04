<?php

namespace App\Enums;

/**
 * Which of a fork's two lanes a step hangs in.
 *
 * Two and no more. A fork with five lanes is a thing people ask for and then
 * cannot read back: the lanes stop fitting beside each other, and every one of
 * them needs its own answer to "and when none of these hold". Two lanes always
 * cover the ground between them, which is what makes a fork legible at a
 * glance — and a fork inside a lane is how somebody says the third thing.
 */
enum WorkflowBranch: string
{
    case Then = 'then';
    case Else = 'else';

    public function label(): string
    {
        return match ($this) {
            self::Then => __('enums.workflow-branch.label.Then'),
            self::Else => __('enums.workflow-branch.label.Else'),
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
