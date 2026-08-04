<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The prikbord: notices that belong to the workspace rather than to a
     * channel.
     *
     * Deliberately not a channel with a posting policy, which is the shape this
     * could easily have taken. A channel is a conversation and is read in the
     * order things were said; a prikbord is a list of things that stay up until
     * they stop being true, and the newest is not automatically the one on top.
     * That difference is the whole feature — everything below follows from it.
     *
     * Keyed by ULID like Message, Poll and Transfer: a post's id travels in a
     * URL, and a sequential number there would let anybody count how many
     * notices every workspace on the installation has put up.
     */
    public function up(): void
    {
        Schema::create('board_posts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * The author, who may leave without taking the notice with them.
             * Null then rather than the row disappearing: "de vakantieregeling
             * staat op het prikbord" has to keep being true after the person who
             * typed it has gone, and a board that empties itself when somebody
             * offboards is a board nobody dares rely on.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('body');

            /*
             * Pinned to the top of the list. A timestamp rather than a boolean,
             * so several pinned notices have an order among themselves — most
             * recently pinned first, which is what somebody pinning a third one
             * expects to happen.
             */
            $table->timestamp('pinned_at')->nullable();

            // Same rule as a message and a ticket comment: rewriting is allowed,
            // rewriting silently is not.
            $table->timestamp('edited_at')->nullable();

            /*
             * Soft deleted, and note the difference with a ticket comment: there
             * the tombstone stays visible because a support history with a hole
             * in it is worthless. A withdrawn notice is simply gone from the
             * board — it was an announcement, and one that has been taken down
             * should not go on announcing that it existed. The row is kept for
             * the same reason every delete here is reversible.
             */
            $table->softDeletes();
            $table->timestamps();

            // How the board is read: pinned first, newest next, within one
            // workspace.
            $table->index(['workspace_id', 'pinned_at', 'created_at']);
        });

        Schema::create('board_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('board_post_id')->constrained()->cascadeOnDelete();

            /*
             * Cascade rather than the null the post above allows. A notice
             * outlives its author because the notice is the point; a reaction
             * with nobody behind it is a sentence nobody can weigh, and the
             * board is not a record anyone has to reconstruct afterwards.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('body');
            $table->timestamp('edited_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['board_post_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_comments');
        Schema::dropIfExists('board_posts');
    }
};
