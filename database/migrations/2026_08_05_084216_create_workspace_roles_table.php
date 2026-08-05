<?php

use App\Enums\BroadcastMentionPolicy;
use App\Enums\ChannelCreationPolicy;
use App\Enums\SystemRole;
use App\Enums\WorkspaceAbility;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A role stops being one of four words and becomes a row a workspace
         * owns. What that buys is the thing the four could not do: a name that
         * means something here — "Leverancier", "Vrijwilliger" — with its own
         * answer to each question.
         */
        Schema::create('workspace_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * The stable name, which is not the readable one. A workspace may
             * rename "Lid" to whatever suits it, and everything that has to
             * recognise a role across that rename — the seed, the tests, the
             * screen that refuses to delete a built-in — reads this instead.
             */
            $table->string('key');
            $table->string('name');

            /*
             * Whether somebody in this role is from outside.
             *
             * A column rather than one more entry in the bag below, and that is
             * the load-bearing decision in this whole table. Channel visibility
             * is decided in SQL — see Channel::scopeVisibleTo — so the question
             * "may they see the workspace at all" has to be answerable in a
             * join. A right in a jsonb bag cannot be, and the day somebody
             * writes a query that forgets to unpack it is the day a guest sees
             * every public channel.
             */
            $table->boolean('is_external')->default(false);

            /*
             * The four a workspace starts with. Not deletable, because a
             * workspace with no role that can manage it is one nobody can get
             * back into — and because the invitation screens name them.
             */
            $table->boolean('is_system')->default(false);

            $table->unsignedInteger('position')->default(0);

            /*
             * Which rights this role holds, as the catalogue spells them. A bag
             * rather than a pivot table: the list is closed, short and read in
             * full every time it is read at all, so a second table would be a
             * join for something that is never queried across roles.
             */
            $table->jsonb('abilities')->default('[]');

            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
            $table->index(['workspace_id', 'position']);
        });

        /*
         * The new pointer, beside the old string rather than instead of it.
         *
         * Everything still reads workspace_user.role today; this migration only
         * has to make the rows exist and line up. Flipping the reads is a
         * change of its own, and doing both at once would mean a deploy where
         * every permission check in the application is new at the same moment.
         */
        foreach (['workspace_user', 'invitations', 'invite_links'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('workspace_role_id')
                    ->nullable()
                    ->after('role')
                    ->constrained('workspace_roles')
                    // Not cascade: a role that somebody holds is not a role
                    // anybody may delete out from under them. The screen that
                    // deletes one has to move its members first.
                    ->restrictOnDelete();
            });
        }

        $this->seedExistingWorkspaces();
    }

    /**
     * Give every workspace that already exists the four it has been using.
     *
     * Read from the enum's own defaults rather than written out again, with two
     * exceptions taken from the workspace itself: whoever may open a channel and
     * whoever may notify a whole one. Those two are settings a workspace has
     * already made, and starting them from the default would quietly undo it.
     */
    private function seedExistingWorkspaces(): void
    {
        DB::table('workspaces')
            ->select('id', 'broadcast_mentions', 'channel_creation')
            ->orderBy('id')
            ->chunk(100, function ($workspaces): void {
                foreach ($workspaces as $workspace) {
                    $this->seed($workspace);
                }
            });
    }

    private function seed(object $workspace): void
    {
        $now = now();

        foreach (SystemRole::cases() as $position => $role) {
            $abilities = array_map(
                fn (WorkspaceAbility $ability): string => $ability->value,
                $role->defaultAbilities(),
            );

            $abilities = $this->applyWorkspaceSettings($abilities, $role, $workspace);

            $id = DB::table('workspace_roles')->insertGetId([
                'workspace_id' => $workspace->id,
                'key' => $role->value,
                'name' => $role->getLabel(),
                'is_external' => $role->isExternal(),
                'is_system' => true,
                'position' => $position,
                'abilities' => json_encode(array_values($abilities)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (['workspace_user', 'invitations', 'invite_links'] as $table) {
                DB::table($table)
                    ->where('workspace_id', $workspace->id)
                    ->where('role', $role->value)
                    ->update(['workspace_role_id' => $id]);
            }
        }
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private function applyWorkspaceSettings(array $abilities, SystemRole $role, object $workspace): array
    {
        $set = function (array $abilities, WorkspaceAbility $ability, bool $holds): array {
            $abilities = array_values(array_diff($abilities, [$ability->value]));

            return $holds ? [...$abilities, $ability->value] : $abilities;
        };

        $mentions = BroadcastMentionPolicy::tryFrom((string) $workspace->broadcast_mentions);

        $abilities = $set($abilities, WorkspaceAbility::BroadcastMention, match ($mentions) {
            // Guests are left out of "everyone" on purpose: the policy this
            // replaces asked the role first and the setting second.
            BroadcastMentionPolicy::Everyone => ! $role->isExternal(),
            BroadcastMentionPolicy::Nobody => false,
            default => $role->canManageWorkspace(),
        });

        $channels = ChannelCreationPolicy::tryFrom((string) $workspace->channel_creation);

        return $set($abilities, WorkspaceAbility::CreateChannels, match ($channels) {
            ChannelCreationPolicy::Admins => $role->canManageWorkspace(),
            default => ! $role->isExternal(),
        });
    }

    public function down(): void
    {
        foreach (['workspace_user', 'invitations', 'invite_links'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('workspace_role_id');
            });
        }

        Schema::dropIfExists('workspace_roles');
    }
};
