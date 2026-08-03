<?php

namespace Database\Factories;

use App\Models\SecretRequest;
use App\Models\SecretRequestKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecretRequestKey>
 */
class SecretRequestKeyFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'secret_request_id' => SecretRequest::factory(),
            'name' => mb_strtoupper(fake()->unique()->word()).'_KEY',
            'hint' => null,
            'position' => 0,
        ];
    }
}
