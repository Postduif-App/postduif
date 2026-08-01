<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The message this one is quoting, if any.
     *
     * Deliberately not parent_id. A quote is an ordinary message in the channel
     * that happens to point at an older one; parent_id turns a message into a
     * thread reply, which takes it out of the channel and moves counters. Two
     * different things that would otherwise fight over one column.
     *
     * nullOnDelete rather than cascade: a quote is the quoting member's own
     * words, and removing the message they answered must not take those with
     * it. That only fires on a hard delete — the usual soft delete leaves the
     * row in place, and the quote then renders as a tombstone.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignUlid('quoted_message_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('quoted_message_id');
        });
    }
};
