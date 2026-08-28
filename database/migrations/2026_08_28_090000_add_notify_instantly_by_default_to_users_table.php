<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a channel with nothing said about it pushes right away, rather
     * than waiting for the away-summary threshold.
     *
     * Off by default, matching notify_via_push: an account that has never
     * touched this setting keeps the quieter behaviour it already had, and a
     * member who wants everything instant says so once here instead of
     * switching every channel by hand. A single channel can still say the
     * opposite — see instant_notifications on channel_user, which overrides
     * this per member per channel.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_instantly_by_default')->default(false)->after('notify_via_push');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_instantly_by_default');
        });
    }
};
