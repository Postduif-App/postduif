<?php

namespace Database\Factories;

use App\Enums\FormFieldType;
use App\Models\FormAnswer;
use App\Models\FormSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormAnswer>
 */
class FormAnswerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_submission_id' => FormSubmission::factory(),
            'form_field_id' => null,
            'field_key' => 'reden',
            'question' => 'Waarom vraag je dit aan?',
            'type' => FormFieldType::ShortText,
            'value' => 'Twee weken zon.',
            'position' => 0,
        ];
    }
}
