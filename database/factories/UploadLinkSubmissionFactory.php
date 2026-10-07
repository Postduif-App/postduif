<?php

namespace Database\Factories;

use App\Models\UploadLink;
use App\Models\UploadLinkSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UploadLinkSubmission>
 */
class UploadLinkSubmissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'upload_link_id' => UploadLink::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'note' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => 'Pest',
        ];
    }
}
