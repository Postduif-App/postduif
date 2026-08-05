<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An invitation names a role of this workspace rather than one of four
     * words.
     *
     * The pointer has been kept in step since the roles arrived, so this only
     * has to catch the rows written before that and drop the string. Anything
     * still pointing nowhere is an invitation for a role that no longer exists
     * — it gets the ordinary one, which is what "member" meant when it was
     * written.
     */
    public function up(): void
    {
        foreach (['invitations', 'invite_links'] as $table) {
            DB::table($table)
                ->whereNull('workspace_role_id')
                ->update([
                    'workspace_role_id' => DB::raw(
                        '(select id from workspace_roles
                          where workspace_roles.workspace_id = '.$table.'.workspace_id
                            and workspace_roles.key = '.$table.'.role
                          limit 1)'
                    ),
                ]);

            DB::table($table)
                ->whereNull('workspace_role_id')
                ->update([
                    'workspace_role_id' => DB::raw(
                        '(select id from workspace_roles
                          where workspace_roles.workspace_id = '.$table.".workspace_id
                            and workspace_roles.key = 'member'
                          limit 1)"
                    ),
                ]);

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        foreach (['invitations', 'invite_links'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('role')->default('member')->after('workspace_id');
            });
        }
    }
};
