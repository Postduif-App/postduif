<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emoji under a notice on the prikbord.
 *
 * Its own table rather than a board_post_id alongside message_id in `reactions`.
 * That table's unique index is what actually keeps one person's emoji single,
 * and an index over a pair of columns that is null half the time enforces
 * nothing on either half — a nullable owner is how "one reaction per person"
 * quietly stops being true.
 *
 * Deliberately without soft deletes, unlike the notice it hangs under. Taking a
 * reaction back is not withdrawing a statement, it is not having made one, and a
 * tombstone would leave the count wrong for everybody reading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_post_reactions', function (Blueprint $table) {
            $table->id();
            // Ulid, because BoardPost is keyed by one: a foreignId here would
            // silently store 0 for every notice on the board.
            $table->foreignUlid('board_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 32);
            $table->timestamps();

            // Two clicks arriving at once both read "not reacted yet". This is
            // what settles it, rather than the check that precedes them.
            $table->unique(['board_post_id', 'user_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_post_reactions');
    }
};
