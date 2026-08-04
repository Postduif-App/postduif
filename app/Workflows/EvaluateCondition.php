<?php

namespace App\Workflows;

use App\Enums\WorkflowConditionMatch;
use App\Enums\WorkflowConditionOperator;
use App\Enums\WorkflowConditionOutcome;

/**
 * Decide whether a step gets its turn.
 *
 * A condition is a handful of rules — a path into the run's memory, a
 * comparison, something to compare against — read together as "all of these" or
 * "any of these", and an answer to what should happen when they say no. Read
 * against the same context the variables come from, on purpose: a beheerder who
 * has just learned that {{ trigger.user.name }} exists should not have to learn
 * a second vocabulary to ask a question about it.
 */
class EvaluateCondition
{
    public function __construct(
        private readonly ResolveVariables $variables,
    ) {}

    /**
     * @param  array<string, mixed>|null  $condition
     * @param  array<string, mixed>  $context
     */
    public function passes(?array $condition, array $context): bool
    {
        $rules = $this->rules($condition);

        /*
         * Nothing to check means run. That covers null and the empty array a
         * form leaves behind when somebody opened the panel and closed it
         * again, and it covers a condition whose last rule was taken out —
         * treating any of those as a condition that is never met would silence
         * the step for a reason nobody could see.
         */
        if ($rules === []) {
            return true;
        }

        $match = WorkflowConditionMatch::tryFrom((string) ($condition['match'] ?? ''))
            ?? WorkflowConditionMatch::All;

        foreach ($rules as $rule) {
            $held = $this->holdsFor($rule, $context);

            /*
             * Decided the moment it can be. Beyond the small saving, it means
             * "any" with one true rule never reads the paths of the rest —
             * which for a webhook payload is the difference between looking at
             * one key and flattening the whole body.
             */
            if ($match === WorkflowConditionMatch::Any && $held) {
                return true;
            }

            if ($match === WorkflowConditionMatch::All && ! $held) {
                return false;
            }
        }

        return $match === WorkflowConditionMatch::All;
    }

    /**
     * What should become of the run when this condition says no.
     *
     * Skip when the condition does not say, which is what every condition
     * written before this choice existed means: not this step, rather than not
     * the rest of the workflow.
     *
     * @param  array<string, mixed>|null  $condition
     */
    public function outcome(?array $condition): WorkflowConditionOutcome
    {
        return WorkflowConditionOutcome::tryFrom((string) ($condition['otherwise'] ?? ''))
            ?? WorkflowConditionOutcome::Skip;
    }

    /**
     * The rules in a condition, whichever shape it was saved in.
     *
     * The first version of this feature wrote the three keys flat on the
     * condition itself, and those rows are still out there. Reading one as a
     * single rule is four lines; the alternative is a stored workflow quietly
     * losing its guard on the day this deploys.
     *
     * @param  array<string, mixed>|null  $condition
     * @return list<array<string, mixed>>
     */
    private function rules(?array $condition): array
    {
        if ($condition === null || $condition === []) {
            return [];
        }

        if (isset($condition['rules']) && is_array($condition['rules'])) {
            return array_values(array_filter($condition['rules'], is_array(...)));
        }

        return isset($condition['path']) ? [$condition] : [];
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  array<string, mixed>  $context
     */
    private function holdsFor(array $rule, array $context): bool
    {
        $operator = WorkflowConditionOperator::tryFrom((string) ($rule['operator'] ?? ''));

        /*
         * An operator that is not one of ours. Passing rather than failing: a
         * rule nobody can read should not quietly switch a step off — the step
         * runs, does the visible thing, and somebody notices. A silently
         * skipped step is the failure mode this whole class is meant to avoid.
         */
        if ($operator === null) {
            return true;
        }

        $left = data_get($context, (string) ($rule['path'] ?? ''));

        // The right-hand side may hold variables too, so "is this the same
        // person who wrote it" is expressible without a second concept.
        $right = $this->variables->fill((string) ($rule['value'] ?? ''), $context);

        return $this->compare($operator, $left, $right);
    }

    private function compare(WorkflowConditionOperator $operator, mixed $left, string $right): bool
    {
        $subject = $this->flatten($left);

        return match ($operator) {
            /*
             * Compared as text and without regard for case. Everything on the
             * left arrives from a JSON column, where the id 12 may well be the
             * string "12" — a strict comparison would make a condition's answer
             * depend on how the value happened to be encoded, which is not
             * something anybody filling in the form can see.
             */
            WorkflowConditionOperator::Equals => $this->same($subject, $right),
            WorkflowConditionOperator::NotEquals => ! $this->same($subject, $right),
            WorkflowConditionOperator::Contains => $this->holds($subject, $right),
            WorkflowConditionOperator::NotContains => ! $this->holds($subject, $right),
            WorkflowConditionOperator::IsEmpty => $subject === '',
            WorkflowConditionOperator::IsNotEmpty => $subject !== '',
        };
    }

    private function same(string $subject, string $value): bool
    {
        return mb_strtolower(trim($subject)) === mb_strtolower(trim($value));
    }

    /**
     * An empty needle holds in nothing.
     *
     * PHP says every string contains "", which would make a half-filled
     * condition — the operator chosen, the value not yet typed — pass for
     * everything. That is the shape a condition has while somebody is still
     * writing it.
     */
    private function holds(string $subject, string $needle): bool
    {
        return $needle !== '' && str_contains(mb_strtolower($subject), mb_strtolower($needle));
    }

    /**
     * Whatever came out of the context, as something comparable.
     *
     * An array becomes JSON so that "contains" still means something for a
     * webhook payload, and a missing path becomes the empty string — which is
     * what makes "is leeg" answer true for a path that points at nothing, the
     * way somebody writing the condition would expect.
     */
    private function flatten(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? __('workflows.value.yes') : __('workflows.value.no');
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
