<?php

namespace App\Actions\Workspace;

use App\Enums\SystemRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Somebody making a workspace for themselves.
 *
 * The admin panel has its own way in — an administrator picks an owner from a
 * list of people who already exist. This is the other one: the person doing it
 * is the owner, and until they press the button they belong nowhere at all.
 *
 * Three things have to be true when this returns, which is why it is one
 * action and one transaction. The workspace exists, its maker is in it as
 * owner, and there is somewhere to talk. Any two out of three is a dead end:
 * a workspace nobody is a member of cannot be opened, and one with no channel
 * used to answer 404 at the door.
 */
class CreateWorkspace
{
    public function __construct(private readonly CreateHomeChannel $createHomeChannel) {}

    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $this->slugFor($name),
                'owner_id' => $owner->id,
            ]);

            /*
             * The role row itself, looked up on the workspace that was created
             * a line ago — seedSystemRoles runs on created, so it is there.
             * The old string column beside it is on its way out.
             */
            $workspace->members()->attach($owner->id, [
                'workspace_role_id' => $workspace->roles()
                    ->where('key', SystemRole::Owner->value)
                    ->value('id'),
                'joined_at' => now(),
            ]);

            $this->createHomeChannel->handle($workspace);

            return $workspace;
        });
    }

    /**
     * A readable address, and a free one.
     *
     * The name is somebody's to choose and two people may well choose the
     * same; the slug is in every URL this workspace ever has. So the name goes
     * through untouched and the address quietly gains a suffix — rather than
     * refusing a name because a stranger got there first, which is a rule
     * nobody outside can see the reason for.
     */
    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        $slug = $base;

        // A reserved word counts as taken. The router refuses those outright,
        // so a workspace that got one would be a workspace nobody can open.
        while (
            in_array($slug, Workspace::RESERVED_SLUGS, true)
            || Workspace::query()->where('slug', $slug)->exists()
        ) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }

        return $slug;
    }
}
