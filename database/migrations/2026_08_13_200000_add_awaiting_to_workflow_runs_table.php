<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a waiting run is waiting *for*, when it is not merely waiting.
     *
     * Until now there was one way to be asleep — resume_at, a moment on the
     * clock — and the sweep that wakes runs up reads nothing else. That covers
     * "wacht drie dagen" and cannot express "wacht tot dit contract getekend
     * is", which needs a second way in: from the event, whenever it happens.
     *
     * One JSON column rather than four of the sort, because the things stored
     * here are one fact — which event, about which record, at which step, and
     * which line on the run screen to go back and finish. Split into columns
     * they would be four that are always null together or filled together, and
     * a partial state nothing could mean.
     *
     * resume_at keeps its job unchanged and becomes the deadline: a wait for an
     * event that never comes is a run that sits in this table forever, so every
     * await has a moment at which it gives up and carries on down the other
     * lane. Which means the existing sweep needs no idea that any of this
     * exists — it wakes the run, and the runner works out that the deadline
     * rather than the event is what got there first.
     *
     * No index. The one query that reads this column asks for waiting runs
     * first, and (status, resume_at) — added with the table — already narrows
     * that to the handful of runs that are asleep at all. An index on a JSON
     * document to filter a set that small would be weight on a table that is
     * written to on every step of every run.
     */
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->json('awaiting')->nullable()->after('resume_plan');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn('awaiting');
        });
    }
};
