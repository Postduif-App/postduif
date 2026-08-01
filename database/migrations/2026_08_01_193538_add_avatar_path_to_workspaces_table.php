<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace's own logo.
     *
     * Same storage as a member's avatar and for the same reasons — see the
     * users migration. What differs is who decides and who may look: setting it
     * is for whoever runs the workspace, and seeing it is for its members.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
