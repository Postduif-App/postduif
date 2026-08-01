<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this member put the conversation away, and where they were when they
     * did it.
     *
     * On the pivot rather than on the channel, which is the whole point: a DM
     * belongs to two people, and one of them clearing it out of their sidebar
     * must not take it off the other's screen. Nothing is deleted — the
     * messages stay, and a new one brings the row back.
     *
     * "A new one" is decided by the message id, not by hidden_at: message ids
     * are ULIDs and sort by the moment they were made, while both timestamps
     * here are whole seconds — so a reply that lands in the same second as the
     * click would compare as older than the click and stay hidden. Null means
     * the conversation was empty when it was put away, and anything said in it
     * since is newer than nothing.
     */
    public function up(): void
    {
        Schema::table('channel_user', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable();
            $table->ulid('hidden_message_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table): void {
            $table->dropColumn(['hidden_at', 'hidden_message_id']);
        });
    }
};
