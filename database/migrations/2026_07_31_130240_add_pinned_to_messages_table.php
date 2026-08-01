<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this message is pinned to the top of its channel, and by whom.
     *
     * Columns rather than a pivot table: a message lives in exactly one
     * channel, so "is this pinned" is a property of the row itself. A join
     * table would only earn its keep if the same message could be pinned in
     * several places at once, which it cannot.
     *
     * nullOnDelete on the pinner: the rules of a channel belong to the channel
     * and not to one person, so somebody leaving must not quietly take the
     * intro of every channel they set up with them.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('pinned_at')->nullable();
            $table->foreignId('pinned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The pin bar asks one question per channel — "what is pinned here,
            // in the order it was pinned" — and this index is that question.
            $table->index(['channel_id', 'pinned_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['channel_id', 'pinned_at']);
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropColumn('pinned_at');
        });
    }
};
