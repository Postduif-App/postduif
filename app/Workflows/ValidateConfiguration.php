<?php

namespace App\Workflows;

use App\Enums\WorkflowFieldType;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Hold a saved workflow to what its own fields say they need.
 *
 * The register describes every field a trigger and an action have: what kind of
 * answer it wants, whether it is required, which options it allows, whether a
 * variable may go in it. Until now the request read none of that. A step's
 * configuration was validated as `array` and nothing else, so a required box
 * left empty, a choice that is not on the list, or a channel from somebody
 * else's workspace all saved perfectly well — and turned up as an exception in
 * a run, hours later, at a moment when nobody is looking at the builder.
 *
 * The one moment somebody is there to be told is when they press save. That is
 * the whole argument for this class.
 *
 * It is deliberately strict about a half-written step, and that is a trade: a
 * workflow can no longer be saved with a required field still empty, so
 * "finish it tomorrow" means finishing the step or taking it out. The
 * alternative — saving it and finding out when it runs — is the failure this
 * exists to stop, and a workflow is switched off until somebody switches it on
 * anyway.
 *
 * What it does not do is guess. A channel named by a word rather than an id is
 * left alone, because FindsTargets resolves those by name while the workflow
 * runs and a channel may be renamed between the two moments. Anything holding
 * {{ }} is left alone for the same reason — nobody knows yet what it will say —
 * except for the one thing that can be decided now: whether a variable was
 * allowed there at all.
 */
class ValidateConfiguration
{
    /** Enough for a sentence somebody writes; short of an essay in a JSON column. */
    private const MAX_TEXT = 500;

    private const MAX_LONG_TEXT = 5000;

    /** A field asking for words is asking for a handful, not a dictionary. */
    private const MAX_WORDS = 20;

    private const MAX_WORD = 60;

    public function __construct(private readonly WorkflowRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $data  The already-validated request, so the shape is known.
     *
     * @throws ValidationException
     */
    public function handle(array $data, Workspace $workspace, User $editor): void
    {
        $rules = [];
        $attributes = [];

        $trigger = $this->registry->trigger((string) ($data['trigger_type'] ?? ''));

        if ($trigger !== null) {
            $this->collect($rules, $attributes, 'trigger_config', $trigger::fields(), $workspace, $editor);
        }

        foreach ($this->steps($data) as $path => $step) {
            $action = $this->registry->action((string) ($step['action_type'] ?? ''));

            /*
             * No such action is not this class's to complain about: the request
             * rules already refuse an action_type outside the register, and a
             * fork has none at all.
             */
            if ($action === null) {
                continue;
            }

            $this->collect($rules, $attributes, "{$path}.config", $action::fields(), $workspace, $editor);
        }

        Validator::make($data, $rules, [], $attributes)->validate();
    }

    /**
     * Every step in the request, lanes included, keyed by where it sits.
     *
     * The key is what the message hangs off — "steps.2.branches.then.0.config.title"
     * is what lets the builder put the error on the box it belongs to rather
     * than at the top of the page.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function steps(array $data): array
    {
        $found = [];

        foreach ($data['steps'] ?? [] as $at => $step) {
            if (! is_array($step)) {
                continue;
            }

            $found["steps.{$at}"] = $step;

            foreach (['then', 'else'] as $lane) {
                foreach ($step['branches'][$lane] ?? [] as $index => $inner) {
                    if (is_array($inner)) {
                        $found["steps.{$at}.branches.{$lane}.{$index}"] = $inner;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The rules for one thing's fields, hung under where its configuration sits.
     *
     * @param  array<string, list<mixed>>  $rules
     * @param  array<string, string>  $attributes
     * @param  list<WorkflowField>  $fields
     */
    private function collect(array &$rules, array &$attributes, string $prefix, array $fields, Workspace $workspace, User $editor): void
    {
        foreach ($fields as $field) {
            $at = "{$prefix}.{$field->key}";

            $rules[$at] = [
                $field->required ? 'required' : 'nullable',
                $this->checks($field, $workspace, $editor),
            ];

            // So the default "is verplicht" message names the box on screen
            // rather than the path it arrived under.
            $attributes[$at] = $field->label;
        }
    }

    /**
     * Everything about one field that is not "was it there".
     *
     * One closure rather than a string of rules, because most of these have a
     * sentence worth saying: "kies er een van de lijst" is help, and
     * "geselecteerde waarde is ongeldig" is not.
     */
    private function checks(WorkflowField $field, Workspace $workspace, User $editor): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($field, $workspace, $editor): void {
            if ($value === null || $value === '' || $value === []) {
                return;
            }

            if (is_string($value) && str_contains($value, '{{')) {
                /*
                 * The one thing about a variable that can be decided now. What
                 * it will hold is nobody's business until the run, but whether
                 * it was allowed here at all is the field's own declaration —
                 * and a variable typed into a number is a workflow that fails
                 * on its first run for a reason nobody wrote down.
                 */
                if (! $field->acceptsVariables()) {
                    $fail(__('workflows.config.no_variables'));
                }

                return;
            }

            match ($field->type) {
                WorkflowFieldType::Text => $this->text($value, self::MAX_TEXT, $fail),
                WorkflowFieldType::LongText => $this->text($value, self::MAX_LONG_TEXT, $fail),
                WorkflowFieldType::Emoji => $this->text($value, self::MAX_WORD, $fail),
                WorkflowFieldType::Number => is_numeric($value) ? null : $fail(__('workflows.config.not_a_number')),
                WorkflowFieldType::Words => $this->words($value, $fail),
                WorkflowFieldType::Choice => $this->choice($field, $value, $fail),
                WorkflowFieldType::Channel => $this->channel($value, $workspace, $fail),
                WorkflowFieldType::Member => $this->member($value, $workspace, $fail),
                WorkflowFieldType::Form => $this->form($value, $workspace, $fail),
                WorkflowFieldType::Record => $this->record($field, $value, $workspace, $editor, $fail),
            };
        };
    }

    private function text(mixed $value, int $max, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('workflows.config.not_text'));

            return;
        }

        if (mb_strlen($value) > $max) {
            $fail(__('workflows.config.too_long', ['max' => $max]));
        }
    }

    private function words(mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail(__('workflows.config.not_words'));

            return;
        }

        if (count($value) > self::MAX_WORDS) {
            $fail(__('workflows.config.too_many_words', ['max' => self::MAX_WORDS]));

            return;
        }

        foreach ($value as $word) {
            if (! is_string($word) || mb_strlen($word) > self::MAX_WORD) {
                $fail(__('workflows.config.too_long', ['max' => self::MAX_WORD]));

                return;
            }
        }
    }

    private function choice(WorkflowField $field, mixed $value, Closure $fail): void
    {
        /*
         * Compared as strings because the options come out of an enum and the
         * value out of a JSON column: the weekday field is keyed '1'..'7' and
         * arrives as whichever of the two the browser felt like sending.
         */
        $allowed = array_map(strval(...), array_keys($field->options));

        if (! in_array((string) $value, $allowed, true)) {
            $fail(__('workflows.config.unknown_choice'));
        }
    }

    /**
     * A channel by id has to be one of this workspace's.
     *
     * A channel by name is left to the run. FindsTargets looks those up by
     * name, on purpose — "#storingen" is how somebody thinks about it — and a
     * channel renamed after the workflow was written would otherwise make an
     * untouched workflow refuse to save.
     */
    private function channel(mixed $value, Workspace $workspace, Closure $fail): void
    {
        $id = (string) $value;

        if (! ctype_digit($id)) {
            return;
        }

        if ($workspace->channels()->whereKey($id)->doesntExist()) {
            $fail(__('workflows.config.channel_not_found'));
        }
    }

    private function member(mixed $value, Workspace $workspace, Closure $fail): void
    {
        if ($workspace->members()->whereKey((string) $value)->doesntExist()) {
            $fail(__('workflows.config.member_not_found'));
        }
    }

    private function form(mixed $value, Workspace $workspace, Closure $fail): void
    {
        if ($workspace->forms()->whereKey((string) $value)->doesntExist()) {
            $fail(__('workflows.config.form_not_found'));
        }
    }

    /**
     * The record has to exist in this workspace, and be one the person writing
     * the workflow can see.
     *
     * Both, because a picker that would not have offered it is not a list to
     * pick from — and the runner asks the same question again of the workflow's
     * owner, who is not necessarily the person editing it.
     */
    private function record(WorkflowField $field, mixed $value, Workspace $workspace, User $editor, Closure $fail): void
    {
        if ($field->record === null) {
            return;
        }

        $record = $field->record->find($workspace, (string) $value);

        if ($record === null || $editor->cannot('view', $record)) {
            $fail(__('workflows.config.record_not_found', ['what' => $field->record->label()]));
        }
    }
}
