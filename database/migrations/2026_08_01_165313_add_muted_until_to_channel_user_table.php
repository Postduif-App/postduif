<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a mute runs out, or null for one that does not.
     *
     * Beside muted_at rather than instead of it, and the two answer different
     * questions: muted_at is when somebody asked for quiet, muted_until is how
     * long they asked for. Keeping both means "gedempt tot morgen" and "gedempt
     * totdat ik hem weer aanzet" are the same feature with one field different,
     * and every row that was muted before this migration keeps working — no
     * end date reads as no end.
     */
    public function up(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->timestamp('muted_until')->nullable()->after('muted_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropColumn('muted_until');
        });
    }
};
