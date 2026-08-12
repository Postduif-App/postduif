<?php

namespace Database\Factories;

use App\Enums\ContractFieldType;
use App\Models\Contract;
use App\Models\ContractField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractField>
 */
class ContractFieldFactory extends Factory
{
    /**
     * A text box a third of the way down the first page.
     *
     * Coordinates that are neither 0 nor 1, on purpose: a default of zero would
     * let a bug that drops the coordinates pass every test that does not check
     * them by name.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'page' => 1,
            'x' => 0.12,
            'y' => 0.34,
            'width' => 0.28,
            'height' => 0.028,
            'type' => ContractFieldType::Text,
            'label' => fake()->words(2, true),
            'is_required' => true,
            'position' => 0,
            'signer_index' => null,
        ];
    }

    public function ofType(ContractFieldType $type): static
    {
        return $this->state([
            'type' => $type,
            ...$type->defaultSize(),
        ]);
    }

    public function signature(): static
    {
        return $this->ofType(ContractFieldType::Signature);
    }

    public function optional(): static
    {
        return $this->state(['is_required' => false]);
    }

    /** For the second signer, the third, and so on — counting from zero. */
    public function forSigner(int $index): static
    {
        return $this->state(['signer_index' => $index]);
    }

    public function onPage(int $page): static
    {
        return $this->state(['page' => $page]);
    }
}
