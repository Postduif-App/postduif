<?php

namespace Database\Seeders;

use App\Enums\ChannelType;
use App\Enums\SystemRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Fill an existing workspace with colleagues to click around with.
 *
 * Unlike DatabaseSeeder this one does not build a workspace: it joins one that
 * is already there, named by slug, so a development database that somebody has
 * been using keeps everything in it.
 */
class WorkspaceMembersSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Which workspace to fill, and with whom.
     *
     * Handles are spelled out rather than left to the factory so the same run
     * twice does not produce a second Fenna: the accounts are matched on
     * username below.
     *
     * @var list<array{name: string, username: string, email: string, role: SystemRole}>
     */
    private const PEOPLE = [
        ['name' => 'Fenna de Vries', 'username' => 'fenna', 'email' => 'fenna@example.com', 'role' => SystemRole::Admin],
        ['name' => 'Joris Bakker', 'username' => 'joris', 'email' => 'joris@example.com', 'role' => SystemRole::Member],
        ['name' => 'Amara Okafor', 'username' => 'amara', 'email' => 'amara@example.com', 'role' => SystemRole::Member],
        ['name' => 'Tobias Meijer', 'username' => 'tobias', 'email' => 'tobias@example.com', 'role' => SystemRole::Member],
        ['name' => 'Nadia el Amrani', 'username' => 'nadia', 'email' => 'nadia@example.com', 'role' => SystemRole::Member],
        ['name' => 'Ruben Hoekstra', 'username' => 'ruben', 'email' => 'ruben@example.com', 'role' => SystemRole::Member],
        ['name' => 'Iris Vermeulen', 'username' => 'iris', 'email' => 'iris@example.com', 'role' => SystemRole::Guest],
    ];

    public function run(): void
    {
        $slug = config('chat.seed_workspace');

        $workspace = Workspace::query()->where('slug', $slug)->first();

        if (! $workspace instanceof Workspace) {
            $this->command->error("Geen workspace met slug [{$slug}].");

            return;
        }

        /*
         * The public channels get everybody. A member who is in the workspace
         * but in no channel opens the app on an empty sidebar with nothing to
         * browse to — the same reason the invitation actions attach channels
         * in the transaction that adds the membership.
         */
        $publicChannels = $workspace->channels()
            ->where('type', ChannelType::Public)
            ->pluck('id');

        $roleIds = $workspace->roles()->pluck('id', 'key');

        DB::transaction(function () use ($workspace, $publicChannels, $roleIds): void {
            foreach (self::PEOPLE as $person) {
                $user = User::firstWhere('username', $person['username'])
                    ?? User::factory()->create([
                        'name' => $person['name'],
                        'username' => $person['username'],
                        'email' => $person['email'],
                    ]);

                // syncWithoutDetaching rather than attach: running the seeder
                // twice should not hand somebody a second membership row, and
                // should not overwrite a role they were since given by hand.
                $workspace->members()->syncWithoutDetaching([
                    $user->id => [
                        'workspace_role_id' => $roleIds[$person['role']->value],
                        'joined_at' => now(),
                    ],
                ]);

                // A guest belongs to the workspace only as far as the channels
                // they were let into, so they are left out of the general ones.
                if ($person['role'] === SystemRole::Guest) {
                    continue;
                }

                foreach ($publicChannels as $channelId) {
                    $user->channels()->syncWithoutDetaching([
                        $channelId => ['joined_at' => now()],
                    ]);
                }
            }
        });

        $this->command->info(sprintf(
            '%d gebruikers in [%s] gezet.',
            count(self::PEOPLE),
            $workspace->name,
        ));
    }
}
