<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A workflow is one trigger and a row of steps. The trigger lives here
         * because there is exactly one of it; the steps get a table of their
         * own because there is no telling how many.
         */
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            /*
             * The trigger's own key — "message-keyword", "webhook" — as the
             * register spells it, with whatever that trigger needs beside it.
             *
             * jsonb rather than json: Postgres has no equality operator for
             * json, and one such column is enough to break every "select
             * distinct" that Filament writes on its own.
             */
            $table->string('trigger_type');
            $table->jsonb('trigger_config')->default('{}');

            /*
             * A timestamp rather than a boolean, so switching one off records
             * when. That is the thing people actually ask about a workflow that
             * has gone quiet: since when.
             */
            $table->timestamp('enabled_at')->nullable();

            /*
             * Whose rights the steps run with — see the channel actions. A
             * workflow that outlives its author should stop rather than quietly
             * run as nobody, so this is nulled and the runner refuses an
             * ownerless workflow.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            /*
             * The listener's question, in one index: which workflows in this
             * workspace are switched on and waiting for this kind of event.
             * Asked once per message posted, so it has to be cheap.
             */
            $table->index(['workspace_id', 'trigger_type', 'enabled_at']);
        });

        /*
         * Steps as rows rather than one JSON column on the workflow, because a
         * step needs an identity: every run writes a line per step, and without
         * a row to point at there is no saying which one stumbled.
         *
         * What a step is configured with *is* JSON, because every action asks
         * for something different — a column per action would grow the table
         * every time the register does.
         */
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('position');

            $table->string('action_type');
            $table->jsonb('config')->default('{}');

            /*
             * When this step should be skipped. Null is the ordinary case —
             * always run — and is deliberately not the same as an empty object,
             * which would be a condition somebody meant to fill in.
             */
            $table->jsonb('condition')->nullable();

            $table->timestamps();

            // Every read is "this workflow's steps, in order".
            $table->index(['workflow_id', 'position']);
        });

        /*
         * One run is one time the row was walked. It exists for two things at
         * once: so somebody can see afterwards what happened, and so a workflow
         * that is waiting has somewhere to keep its place.
         */
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();

            $table->string('status');

            /*
             * What the trigger handed over, plus what each step gave back. This
             * is what the {{ ... }} in a step's configuration reads from, which
             * is why it is stored rather than held in memory: a run that waits
             * an hour has to find the same values again afterwards.
             *
             * It therefore holds message text and people's names. That is why
             * the run screen belongs to whoever administers the workspace, and
             * why runs get cleared out in time.
             */
            $table->jsonb('context')->default('{}');

            /*
             * Where to pick up. Only meaningful while waiting — see the delay
             * step — and it is a position rather than a step id, so that a step
             * deleted mid-wait cannot resume into a row that is no longer there.
             */
            $table->unsignedInteger('resume_position')->default(0);
            $table->timestamp('resume_at')->nullable();

            $table->timestamp('finished_at')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // The run list of one workflow, newest first.
            $table->index(['workflow_id', 'created_at']);

            // What the resumer sweeps for: runs whose waiting is over.
            $table->index(['status', 'resume_at']);
        });

        /*
         * A line per step per run, including the ones that did nothing. A
         * skipped step that left no trace is indistinguishable from a step that
         * never got its turn, and "why did nothing happen" is the question this
         * table exists to answer.
         */
        Schema::create('workflow_step_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained()->cascadeOnDelete();

            /*
             * Nulled rather than removed when the step goes: the record of what
             * a run did should survive somebody editing the workflow
             * afterwards. The position and the action are copied here for the
             * same reason.
             */
            $table->foreignId('workflow_step_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position');
            $table->string('action_type');

            $table->string('status');
            $table->jsonb('result')->nullable();
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['workflow_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_step_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
