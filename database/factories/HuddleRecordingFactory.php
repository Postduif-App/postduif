<?php

namespace Database\Factories;

use App\Models\Huddle;
use App\Models\HuddleRecording;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HuddleRecording>
 */
class HuddleRecordingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'huddle_id' => Huddle::factory(),
            'recorded_by' => User::factory(),
            'duration_seconds' => 300,
        ];
    }

    /** One whose words are in. */
    public function transcribed(string $transcript = 'Dit is wat er gezegd is.'): self
    {
        return $this->state(fn (): array => [
            'transcript' => $transcript,
            'transcribed_at' => now(),
        ]);
    }
}
