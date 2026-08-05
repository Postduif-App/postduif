<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The stretches of time somebody was at work.
     *
     * A row per stretch rather than a running total on the membership. A total
     * cannot be corrected, cannot say when the day began, and cannot survive
     * somebody forgetting to clock out — all three of which are the ordinary
     * case rather than the exception.
     *
     * Against the workspace as well as the member, because clocking in is
     * something you do for an employer. Somebody in two workspaces has two
     * working days that have nothing to say to each other, and a row that only
     * knew the member could not tell them apart.
     */
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * Both ends as absolute instants, in UTC like everything else here.
             *
             * The opposite choice from StatusRule, and deliberately so. A rule
             * says "nine o'clock", which is a reading on a clock and means
             * whatever the member's own clock says. This says "then", which is
             * a moment that happened — and a moment that happened does not move
             * when somebody flies to Lisbon or when the clocks go back.
             *
             * Which day it counts towards is a separate question, answered in
             * the member's own zone when the totals are added up.
             */
            $table->timestamp('started_at');

            /*
             * Null while the shift is still running. That is what "clocked in"
             * means here — there is no separate flag, because a flag and a
             * timestamp can disagree and then nobody knows which one is true.
             */
            $table->timestamp('ended_at')->nullable();

            /*
             * When somebody adjusted the times afterwards, so the overview can
             * be honest about it. Null for a stretch that is exactly what the
             * clock recorded.
             */
            $table->timestamp('corrected_at')->nullable();

            $table->timestamps();

            // How the week is read back: this member, this workspace, newest
            // first.
            $table->index(['workspace_id', 'user_id', 'started_at']);
        });

        /*
         * At most one running shift per member per workspace.
         *
         * Said by the database rather than checked in the action, because the
         * two ways to end up with two open shifts are a double click and two
         * tabs — both of which are two requests that each saw no open shift
         * before either had written one. No amount of looking first fixes that;
         * a unique index does.
         *
         * Partial, so it only constrains the open ones. A member has as many
         * finished shifts as they have worked days, and those must be free to
         * repeat.
         */
        DB::statement(<<<'SQL'
            create unique index time_entries_one_open_shift
                on time_entries (workspace_id, user_id)
                where ended_at is null
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
