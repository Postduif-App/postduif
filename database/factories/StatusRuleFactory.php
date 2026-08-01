<?php

namespace Database\Factories;

use App\Enums\Availability;
use App\Models\StatusRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StatusRule>
 */
class StatusRuleFactory extends Factory
{
    protected $model = StatusRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'position' => 0,
            'days' => [],
            'starts_at' => null,
            'ends_at' => null,
            'status_emoji' => '📅',
            'status_text' => 'Volgens schema',
            'availability' => Availability::Available,
        ];
    }

    /** Office hours on the days most people work. */
    public function workdays(string $from = '09:00', string $until = '17:00'): self
    {
        return $this->state(fn (): array => [
            'days' => [1, 2, 3, 4, 5],
            'starts_at' => $from,
            'ends_at' => $until,
        ]);
    }
}
