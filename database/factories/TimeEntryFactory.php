<?php

namespace Database\Factories;

use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    protected $model = TimeEntry::class;

    /**
     * A shift that is over and done with.
     *
     * Finished rather than running by default, because the open one is the
     * special case: there can only ever be a single open shift per member, so a
     * factory that made those by default could not make two rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = Carbon::now()->subDays(fake()->numberBetween(0, 20))->setTime(9, 0);

        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'started_at' => $startedAt,
            'ended_at' => $startedAt->copy()->addHours(8),
        ];
    }

    /** Clocked in and not yet out. */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => null,
        ]);
    }

    public function startedAt(Carbon $startedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => $startedAt,
            'ended_at' => $attributes['ended_at'] === null
                ? null
                : $startedAt->copy()->addHours(8),
        ]);
    }

    /** A shift of a given length, ending when it was told to. */
    public function lasting(float $hours): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => Carbon::parse($attributes['started_at'])->addMinutes((int) round($hours * 60)),
        ]);
    }
}
