<?php

namespace Database\Factories;

use App\Models\UploadLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<UploadLink>
 */
class UploadLinkFactory extends Factory
{
    /**
     * The ordinary case: open for a week, as often as the customer likes.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'created_by' => User::factory(),
            'notify_channel_id' => null,
            'token' => UploadLink::freshToken(),
            'title' => fake()->words(3, true),
            'message' => null,
            'expires_at' => now()->addWeek(),
            'max_uploads' => null,
            'uploads' => 0,
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()->subHour()]);
    }

    /** Used as often as it was allowed, so the next sender is turned away. */
    public function exhausted(int $max = 1): static
    {
        return $this->state(['max_uploads' => $max, 'uploads' => $max]);
    }

    public function locked(string $password = 'geheim123'): static
    {
        return $this->state(['password' => Hash::make($password)]);
    }
}
