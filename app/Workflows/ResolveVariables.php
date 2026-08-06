<?php

namespace App\Workflows;

/**
 * Fill the {{ ... }} in a step's settings with what the run actually saw.
 *
 * Without this every workflow is a fixed sentence. With it a step can say
 * "Welkom {{ trigger.user.name }}" or post in the channel the trigger came
 * from, and the same workflow means something different each time it runs.
 *
 * Reading only. No expressions, no calls, no arithmetic — the day something
 * executable is allowed in here, this stops being a text field and becomes a
 * scripting environment that every beheerder may run.
 */
class ResolveVariables
{
    /**
     * A path and nothing else between the braces.
     *
     * Letters, digits, dots, dashes and underscores — enough for
     * trigger.user.name and steps.0.channel.id, and deliberately not enough for
     * anything with a bracket, a quote or a space in it. A pattern that accepts
     * more is a pattern somebody will eventually put a function call through.
     */
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_.-]+)\s*\}\}/';

    /**
     * @param  array<string, mixed>  $config  As the step stored it.
     * @param  list<WorkflowField>  $fields  What the action says it accepts.
     * @param  array<string, mixed>  $context  The run's memory.
     * @return array<string, mixed>
     */
    public function handle(array $config, array $fields, array $context): array
    {
        foreach ($fields as $field) {
            /*
             * What the type says it accepts — the free-text fields and the
             * channel. See WorkflowFieldType::acceptsVariables() for why a
             * channel is safe here and a form is not.
             */
            if (! $field->acceptsVariables()) {
                continue;
            }

            $value = $config[$field->key] ?? null;

            if (is_string($value)) {
                $config[$field->key] = $this->fill($value, $context);
            }
        }

        return $config;
    }

    /**
     * One string, with every path in it replaced.
     *
     * @param  array<string, mixed>  $context
     */
    public function fill(string $text, array $context): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $match): string => $this->render(data_get($context, $match[1])),
            $text,
        );
    }

    /**
     * What a value looks like once it is part of a sentence.
     *
     * A path that points at nothing becomes empty rather than staying as it was
     * written. A message reading "Welkom {{ trigger.user.name }}" is more
     * confusing than one with a gap in it — the first looks like the
     * application is broken, the second looks like something is missing, which
     * is exactly what happened.
     *
     * false becomes "nee" rather than the empty string PHP would give, because
     * a false that vanishes reads the same as a value that was never there.
     */
    private function render(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? __('workflows.value.yes') : __('workflows.value.no');
        }

        /*
         * A whole branch of the context, which happens most with a webhook: the
         * sender's shape is theirs, and {{ trigger.payload }} is a reasonable
         * thing to write while working out what arrives. Given as JSON rather
         * than as "Array", which is what a plain cast would produce.
         */
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
