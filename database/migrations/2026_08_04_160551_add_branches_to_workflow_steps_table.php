<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            /*
             * What sort of step this is. Nearly always an action; the other
             * kind is a fork, which does nothing itself and picks one of two
             * lanes for the steps below it.
             *
             * A column rather than a reserved action_type, because the register
             * is the list of things a workflow can *do* — and a fork is not one
             * of them. Smuggling it in as an action would mean every place that
             * resolves an action_type having to know about one that resolves to
             * nothing.
             */
            $table->string('kind')->default('action');

            /*
             * The fork a step hangs under, and which of its two lanes.
             *
             * Null for the ordinary case: a step in the workflow's own row. The
             * pair is what makes a workflow a shape rather than a list, and it
             * cascades — a fork that is deleted takes both its lanes with it,
             * which is the only reading that does not leave steps behind that
             * nothing will ever run.
             */
            $table->foreignId('parent_step_id')->nullable()->constrained('workflow_steps')->cascadeOnDelete();
            $table->string('branch')->nullable();

            /*
             * One level of the shape at a time, which is how the builder reads
             * it and how the runner steps into a lane.
             *
             * Position keeps meaning what it meant: one number per step, unique
             * within the workflow, counted in the order the steps are written
             * down — a fork first, then its then-lane, then its else-lane, then
             * whatever follows the fork. Keeping it whole is what lets a step
             * still be spoken of as {{ steps.3.channel.id }} after the workflow
             * has grown a fork above it.
             */
            $table->index(['workflow_id', 'parent_step_id', 'position']);
        });

        Schema::table('workflow_runs', function (Blueprint $table) {
            /*
             * The steps a waiting run still has ahead of it, by id and in
             * order.
             *
             * resume_position could say "carry on at the fourth step" as long
             * as a workflow was a row. Once it forks, where a run stands is not
             * a number: it is a lane, inside a fork, at a step. Writing the
             * remaining plan down is also the only way a run that resumes an
             * hour later goes down the lane it was already in — re-reading the
             * fork would decide it again against a context that the steps
             * before the wait have since changed.
             *
             * The cost is that a step inserted during the wait is not picked
             * up, where before it would have been. That is the better half of
             * the trade: editing a workflow should not be able to move a
             * running instance into the other lane.
             */
            $table->jsonb('resume_plan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table) {
            $table->dropColumn('resume_plan');
        });

        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropIndex(['workflow_id', 'parent_step_id', 'position']);
            $table->dropConstrainedForeignId('parent_step_id');
            $table->dropColumn(['kind', 'branch']);
        });
    }
};
