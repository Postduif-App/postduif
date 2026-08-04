<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a scheduled workflow last had its turn.
     *
     * A column rather than "look at the newest run", because runs are cleared
     * out in time and the last one may well be gone — at which point every
     * scheduled workflow in the workspace would fire again the next minute.
     *
     * Null for the six workflows out of seven that are not scheduled, and for a
     * scheduled one that has never come round yet.
     */
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->timestamp('schedule_ran_at')->nullable();

            /*
             * What the minute-by-minute sweep asks: which scheduled workflows
             * in the whole installation are switched on. Not scoped to a
             * workspace, unlike every other query on this table — the scheduler
             * has no workspace, it has a clock.
             */
            $table->index(['trigger_type', 'enabled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex(['trigger_type', 'enabled_at']);
            $table->dropColumn('schedule_ran_at');
        });
    }
};
