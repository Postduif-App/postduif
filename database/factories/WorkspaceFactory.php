<?php

namespace Database\Factories;

use App\Enums\WorkspaceAccent;
use App\Enums\WorkspaceFont;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
class WorkspaceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'owner_id' => User::factory(),
        ];
    }

    /**
     * A workspace that has picked its own look.
     */
    public function themed(WorkspaceAccent $accent, WorkspaceFont $font = WorkspaceFont::InstrumentSans): static
    {
        return $this->state(['accent' => $accent, 'font' => $font]);
    }

    /**
     * A workspace that moderates the words below.
     *
     * @param  array<int, string>  $words
     */
    public function blocking(array $words): static
    {
        return $this->state(['blocked_words' => $words]);
    }
}
