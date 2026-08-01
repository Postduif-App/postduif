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
             * Where in the incoming payload the message text sits, in dot
             * notation: "issue.title", "commits.0.message".
             *
             * Null means the original contract — send {"text": "..."} — so
             * every webhook that already exists carries on unchanged. That is
             * also why this is a column and not a mode flag with a path beside
             * it: having a path is what being dynamic means.
             */
            $table->string('body_path')->nullable()->after('bot_name');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropColumn('body_path');
        });
    }
};
