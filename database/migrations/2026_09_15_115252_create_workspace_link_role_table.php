<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who sees which button.
     *
     * A table rather than a list of role ids in a column on the link. A role
     * here is a row a workspace writes and can delete again — see
     * WorkspaceRoleController — so the ids in a JSON column would go on naming
     * roles that no longer exist, and nothing would ever come and tidy them up.
     * A foreign key does that by itself.
     *
     * No rows means no reader, not every reader. That is the direction that
     * survives a mistake: a link somebody forgot to point at anybody stays out
     * of sight, where the opposite reading would put it in front of exactly the
     * people who were never meant to have it. The beheer screen starts with
     * every role ticked, so the empty set is somewhere you have to go
     * deliberately.
     */
    public function up(): void
    {
        Schema::create('workspace_link_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_role_id')
                ->constrained('workspace_roles')
                ->cascadeOnDelete();

            $table->unique(['workspace_link_id', 'workspace_role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_link_role');
    }
};
