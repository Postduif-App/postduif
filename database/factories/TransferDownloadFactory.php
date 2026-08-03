<?php

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferDownload>
 */
class TransferDownloadFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transfer_id' => Transfer::factory(),
            'media_id' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
