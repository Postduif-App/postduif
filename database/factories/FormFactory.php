<?php

namespace Database\Factories;

use App\Models\Form;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'title' => 'Vakantieaanvraag',
            'description' => 'Vul dit in en je leidinggevende krijgt er bericht van.',
            'allows_multiple_submissions' => false,
            'closes_at' => null,
        ];
    }

    /** Somebody stopped it by hand. */
    public function closed(): static
    {
        return $this->state(['closed_at' => now()->subHour()]);
    }

    /** Its moment simply passed — a different thing, and the card says so. */
    public function expired(): static
    {
        return $this->state(['closes_at' => now()->subHour()]);
    }

    public function shared(): static
    {
        return $this->state(['share_token' => Str::random(48)]);
    }

    public function acceptsMore(): static
    {
        return $this->state(['allows_multiple_submissions' => true]);
    }
}
