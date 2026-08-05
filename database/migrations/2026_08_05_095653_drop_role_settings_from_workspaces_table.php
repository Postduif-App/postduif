<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two settings that had become a second way of saying the same thing.
     *
     * Who may open a channel and who may notify a whole one are rights on a
     * role. They were columns here because there were no roles to put them on;
     * the migration that made roles read these to seed them, and everything
     * since has read the role. What is left is a pair of dropdowns saying what
     * the roles screen says better, in words that cannot describe a workspace
     * with five roles.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['broadcast_mentions', 'channel_creation']);
        });
    }

    /**
     * Back to the defaults rather than to what was there.
     *
     * Nothing has read these since the roles arrived, so a workspace that has
     * been editing its roles has no answer to give here — and inventing one
     * from the current rights would write a sentence nobody said. The defaults
     * are what a fresh workspace had.
     */
    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('broadcast_mentions')->default('admins');
            $table->string('channel_creation')->default('everyone');
        });
    }
};
