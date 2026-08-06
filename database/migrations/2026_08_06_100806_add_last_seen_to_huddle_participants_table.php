<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When somebody in a huddle was last heard from.
     *
     * A huddle has no way of noticing that a browser is gone. Closing the tab
     * shuts the connections down locally and says nothing to anybody: the row
     * keeps its empty left_at, the huddle never runs out of participants, and
     * because a channel may hold only one live huddle at a time that channel is
     * then stuck with a conversation nobody is in.
     *
     * Saying so on the way out covers the ordinary case, and cannot cover the
     * one that matters most — a browser that crashed, a laptop that shut, a
     * network that went. Nothing is sent then, by definition. So the huddle
     * asks the other way round: everybody in it keeps saying they are still
     * here, and whoever stops saying it is swept.
     */
    public function up(): void
    {
        Schema::table('huddle_participants', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('joined_at');
        });

        // Everybody who is in one right now counts as just heard from, so the
        // sweep does not clear the huddles that exist while this runs.
        DB::table('huddle_participants')
            ->whereNull('left_at')
            ->update(['last_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('huddle_participants', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
