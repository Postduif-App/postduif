<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a poll's own deadline has been noticed yet.
     *
     * Until now nothing ran when closes_at passed: a poll is closed when the
     * moment has gone by, worked out where it is read — see the original
     * migration, which says so. That is fine for the card in the channel and
     * useless for a workflow, which has to be told at a moment rather than
     * discover it on being looked at.
     *
     * A third timestamp rather than stamping closed_at, which would have been
     * the cheap fix and the wrong one: closed_at means "somebody pressed stop",
     * the card reads differently for the two, and PollController::reopen undoes
     * them separately. This column says only that the deadline has been dealt
     * with, so the sweep does not announce the same poll every minute for the
     * rest of its life.
     */
    public function up(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->timestamp('settled_at')->nullable()->after('closed_at');

            /*
             * What the sweep asks, in the order it asks it: the unsettled ones
             * first, since nearly every poll in the table is already settled or
             * has no deadline at all, and only then how their deadline compares
             * to now.
             */
            $table->index(['settled_at', 'closes_at']);
        });
    }

    public function down(): void
    {
        Schema::table('polls', function (Blueprint $table) {
            $table->dropIndex(['settled_at', 'closes_at']);
            $table->dropColumn('settled_at');
        });
    }
};
