<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceLink>
 */
class WorkspaceLinkFactory extends Factory
{
    protected $model = WorkspaceLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'label' => fake()->words(2, true),
            'url' => fake()->url(),
            'emoji' => null,
            'position' => 0,
        ];
    }

    /**
     * Visible to everybody in the workspace it belongs to.
     *
     * A state rather than the default, because a link with no roles is the
     * honest default — and because attaching them means knowing which workspace
     * the link ended up in, which is only true once it exists.
     */
    public function forEveryone(): static
    {
        return $this->afterCreating(
            fn (WorkspaceLink $link) => $link->roles()->sync(
                $link->workspace->roles()->pluck('id'),
            ),
        );
    }
}
