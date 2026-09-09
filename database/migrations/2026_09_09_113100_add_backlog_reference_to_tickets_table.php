<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Where a ticket came from when it did not start life as a message here.
     * `external_source` names the system ('backlog', and room for another one
     * day); `external_id` is that system's own id for the thing, kept as a
     * string because an issue tracker's id is not necessarily numeric.
     *
     * The unique index on the pair is what makes an inbound webhook safe to
     * retry: Backlog resending the same `issue.created` delivery after a
     * timeout must find the ticket it already made rather than mint a second
     * one. Both columns are nullable, so a ticket that has nothing to do with
     * Backlog carries two nulls each, and Postgres never treats a pair of
     * nulls as a duplicate of another pair of nulls — that is exactly the
     * behaviour wanted here.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('external_source')->nullable()->after('source_message_id');
            $table->string('external_id')->nullable()->after('external_source');
            $table->string('external_url')->nullable()->after('external_id');

            $table->unique(['external_source', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropUnique(['external_source', 'external_id']);
            $table->dropColumn(['external_source', 'external_id', 'external_url']);
        });
    }
};
