<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            /*
             * The last thing this webhook received, so somebody setting a path
             * can look at what actually arrives instead of guessing.
             *
             * Only the last one, and only ever the last one. Whatever a sender
             * posts may contain anything at all — names, addresses, a customer's
             * words — so this is a sample to read once while wiring it up, not
             * a log. It goes with the webhook when the webhook goes.
             *
             * jsonb rather than json: Postgres has no equality operator for
             * json, and one such column is enough to break the "select
             * distinct" Filament writes on its own.
             */
            $table->jsonb('last_payload')->nullable()->after('body_path');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropColumn('last_payload');
        });
    }
};
