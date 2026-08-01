<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What one member decided about one thread.
     *
     * A row exists only once somebody closes a thread, so the absence of a row
     * is the normal state: an active thread shows up for everyone who can see
     * its channel without anything having to be written first.
     *
     * closed_at is a timestamp rather than a flag because a thread can come
     * back to life. Comparing it with the parent's last_reply_at answers "has
     * anything been said since I closed this?" — a boolean cannot, and would
     * need a second column to say when the closing happened.
     */
    public function up(): void
    {
        Schema::create('thread_user', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('closed_at');
            $table->timestamps();

            // One decision per member per thread: closing twice updates the row
            // rather than stacking a second one the query would have to
            // deduplicate away.
            $table->unique(['message_id', 'user_id']);

            // The sidebar asks "which threads has this member closed" on every
            // page load, so the member is the leading column.
            $table->index(['user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_user');
    }
};
