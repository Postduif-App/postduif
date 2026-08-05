<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            /*
             * The name this workflow's messages appear under.
             *
             * Its own column rather than the workflow's name, because the two
             * are read by different people. The name is what a beheerder finds
             * the workflow back by — "standup reminder, second attempt" — and
             * that is not a thing anybody wants to see signing a message in a
             * channel.
             *
             * Nullable, and empty means the workflow's name. Every workflow
             * written before this column existed already posts under its name,
             * so a backfill would only freeze that into a value nobody chose.
             */
            $table->string('bot_name')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('bot_name');
        });
    }
};
