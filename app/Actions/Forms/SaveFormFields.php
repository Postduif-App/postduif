<?php

namespace App\Actions\Forms;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Support\Facades\DB;

/**
 * Put the builder's list of questions where the form's list of questions used
 * to be.
 *
 * Written as a sync rather than as create/update/delete endpoints per field,
 * because that is what the screen is: one list somebody drags about and then
 * saves. Three rules make it safe to do wholesale.
 *
 * A field that came back with an id is updated in place, so answers already
 * given keep pointing at the question they answered. A field with no id is new
 * and gets a key derived from its label — once, here, never again. And a field
 * that is simply missing from the payload is deleted, which nulls the
 * form_field_id on its answers rather than removing them: the answers carry
 * their own copy of the question, which is exactly the case this was built for.
 *
 * What is deliberately not synced is the key of an existing field. A workflow
 * may be reading {{ trigger.answers.reden }}, and renaming the label must not
 * quietly break it.
 */
class SaveFormFields
{
    /**
     * @param  list<array{id?: int|null, type: string, label: string, hint?: string|null, required?: bool, options?: list<string>}>  $fields
     */
    public function handle(Form $form, array $fields): void
    {
        DB::transaction(function () use ($form, $fields): void {
            $kept = [];

            foreach ($fields as $position => $field) {
                $type = FormFieldType::from($field['type']);

                $options = $type->takesOptions()
                    ? array_values(array_filter(
                        array_map(trim(...), $field['options'] ?? []),
                        fn (string $option): bool => $option !== '',
                    ))
                    : [];

                $attributes = [
                    'type' => $type,
                    'label' => trim($field['label']),
                    'hint' => filled($field['hint'] ?? null) ? trim((string) $field['hint']) : null,
                    'required' => (bool) ($field['required'] ?? true),
                    'options' => $options,
                    'position' => $position,
                ];

                $existing = isset($field['id'])
                    ? $form->fields()->whereKey($field['id'])->first()
                    : null;

                if ($existing !== null) {
                    $existing->update($attributes);
                    $kept[] = $existing->id;

                    continue;
                }

                $kept[] = FormField::create([
                    'form_id' => $form->id,
                    'key' => $form->keyFor($attributes['label']),
                    ...$attributes,
                ])->id;
            }

            $form->fields()->whereNotIn('id', $kept === [] ? [0] : $kept)->delete();
        });
    }
}
