<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Talking in a channel, and who is in it.
     *
     * A row rather than a thing that only exists in memory: a huddle has to be
     * findable by somebody who was not looking when it started — that is the
     * whole difference between this and ringing a colleague — and "is there one
     * going on in #support" must have the same answer for everybody.
     *
     * One live huddle per channel, enforced by the partial index below rather
     * than by whoever happens to be starting one: two people pressing the
     * button within the same second is the ordinary case, not a race worth
     * losing. The second of them joins the first's, and the index is what makes
     * that safe to assume.
     *
     * Ended ones stay. They are the record of a conversation that happened,
     * which is worth as much in a channel as a message is — and closing over
     * them would make "wie was erbij" unanswerable an hour later.
     */
    public function up(): void
    {
        Schema::create('huddles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            /*
             * Who pressed the button. Nulled rather than cascaded when they
             * leave the workspace: the huddle happened, and the other people in
             * it are still the answer to what it was.
             */
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'ended_at']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX huddles_one_live_per_channel
            ON huddles (channel_id)
            WHERE ended_at IS NULL
        SQL);

        Schema::create('huddle_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('huddle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at');
            /*
             * Null while somebody is in it. Rejoining clears this again rather
             * than writing a second row: what the huddle needs to know is who
             * is here now, and a list that grew every time somebody's wifi
             * hiccuped would answer a different question.
             */
            $table->timestamp('left_at')->nullable();

            $table->unique(['huddle_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('huddle_participants');
        Schema::dropIfExists('huddles');
    }
};
