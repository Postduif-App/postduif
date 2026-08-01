<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two facts a notification run needs about a membership.
     *
     * last_read_at answers "how long has this person been away from this
     * channel". The pivot's updated_at very nearly answers it too, but it moves
     * for every other reason a membership row is touched — so a column that
     * means one thing.
     *
     * last_notified_message_id is a pointer, for the same reason the read
     * pointer next to it is one: it says how far the notifications have got,
     * and it can only move forward. A "notified_at" timestamp would have to be
     * compared against message timestamps and would send the same messages
     * again the moment the two clocks disagreed.
     *
     * Both nullable and both meaning "never": an existing membership has read
     * nothing since this column existed and has been told about nothing.
     */
    public function up(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('last_read_message_id');
            $table->ulid('last_notified_message_id')->nullable()->after('last_read_at');
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropColumn(['last_read_at', 'last_notified_message_id']);
        });
    }
};
