<?php

namespace Database\Factories;

use App\Enums\FormFieldType;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FormField>
 */
class FormFieldFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'key' => 'veld_'.Str::lower(Str::random(6)),
            'type' => FormFieldType::ShortText,
            'label' => 'Waarom vraag je dit aan?',
            'hint' => null,
            'required' => true,
            'options' => [],
            'position' => 0,
        ];
    }

    /**
     * @param  list<string>  $options
     */
    public function choice(array $options = ['Ja', 'Nee'], bool $multiple = false): static
    {
        return $this->state([
            'type' => $multiple ? FormFieldType::MultipleChoice : FormFieldType::Choice,
            'options' => $options,
        ]);
    }

    public function ofType(FormFieldType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function optional(): static
    {
        return $this->state(['required' => false]);
    }

    public function at(int $position): static
    {
        return $this->state(['position' => $position]);
    }
}
