<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where somebody's face is stored.
     *
     * A column rather than a row in the media table the attachments use, and
     * the reason is worth writing down: that table keys model_id as a ULID,
     * because messages are ULID-keyed, and Postgres refuses to compare a
     * varchar to a user's integer id. One file with no collection semantics
     * does not need a library either — there is exactly one avatar, it replaces
     * whatever was there, and nothing hangs off it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
