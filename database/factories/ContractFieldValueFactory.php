<?php

namespace Database\Factories;

use App\Models\ContractField;
use App\Models\ContractFieldValue;
use App\Models\ContractSigner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractFieldValue>
 */
class ContractFieldValueFactory extends Factory
{
    /**
     * Something typed into a box, and stamped as dealt with.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_field_id' => ContractField::factory(),
            'contract_signer_id' => ContractSigner::factory(),
            'value' => fake()->words(2, true),
            'filled_at' => now(),
        ];
    }

    /**
     * Started but not finished — the half-typed draft the public page saves as
     * somebody works.
     */
    public function draft(): static
    {
        return $this->state(['filled_at' => null]);
    }

    /**
     * A box that was drawn into rather than typed into.
     *
     * No value, because the image hangs on the signer — see ContractSigner's
     * media collections. What makes this an answer at all is filled_at.
     */
    public function drawn(): static
    {
        return $this->state(['value' => null, 'filled_at' => now()]);
    }
}
