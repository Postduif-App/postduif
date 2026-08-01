<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Suspension is the platform-wide counterpart to archiving a channel: the
     * person keeps their account, their messages and their memberships, but they
     * cannot get in any more. Deleting them would take the conversations other
     * members took part in with them, which is why UserPolicy::delete() refuses.
     *
     * A timestamp rather than a boolean, matching admin_at: we want to know when
     * a moderator pulled the lever, not only that someone did.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('admin_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_at');
        });
    }
};
