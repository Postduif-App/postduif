<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'form_id' => Form::factory(),
            'submitted_by' => User::factory(),
            'via_link' => false,
        ];
    }

    /** Somebody who came in over the public link without an account. */
    public function anonymous(): static
    {
        return $this->state(['submitted_by' => null, 'via_link' => true]);
    }

    public function viaLink(): static
    {
        return $this->state(['via_link' => true]);
    }
}
