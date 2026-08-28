<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This member's own override for this channel: push right away for every
     * message (true), stick to the away summary (false), or say nothing and
     * follow the account's own default (null).
     *
     * Nullable rather than a plain boolean, because "not set" is a real third
     * answer here and not the same as "off" — a member's account-wide default
     * has to be able to shine through on every channel they never touched.
     */
    public function up(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->boolean('instant_notifications')->nullable()->after('muted_until');
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropColumn('instant_notifications');
        });
    }
};
