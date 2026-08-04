<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_step_runs', function (Blueprint $table) {
            /*
             * Which lane this step was standing in, copied here for the same
             * reason its position and its action are: what a run says it did
             * must not change when somebody edits the workflow afterwards.
             *
             * Without it the run screen cannot draw the shape at all. The step
             * itself is nulled when it goes, so reading the lane back through
             * the step would work right up until the moment somebody deletes
             * the thing they are trying to understand.
             */
            $table->string('branch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_step_runs', function (Blueprint $table) {
            $table->dropColumn('branch');
        });
    }
};
