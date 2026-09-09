<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backlog_connections', function (Blueprint $table) {
            /**
             * Backlog's own id for the workspace this connection reaches.
             *
             * Nullable: every connection made until now only ever called a
             * flat, single-resource route (`/api/v1/issues/{id}`, `.../comments`),
             * which names one thing by its own id and never needed to say
             * which workspace it belongs to. Creating a new issue does not
             * have that luxury — `POST /api/v1/workspaces/{workspace}/issues`
             * is a collection route, and "which workspace" has no answer a
             * script can be expected to know implicitly (Backlog's own
             * routes/api.php says the same about why the route is shaped this
             * way). A connection only used for the existing sync directions
             * has no need to fill this in.
             */
            $table->unsignedBigInteger('backlog_workspace_id')->nullable()->after('backlog_url');
        });
    }

    public function down(): void
    {
        Schema::table('backlog_connections', function (Blueprint $table) {
            $table->dropColumn('backlog_workspace_id');
        });
    }
};
