<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether every status change is also said in the conversation.
     *
     * Off by default, unlike ticket_announcements next to it: opening and
     * closing are two moments a reader needs, while the moves in between are a
     * stream that turns a channel into something people mute. A team that works
     * out of the conversation rather than out of the board can switch it on.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->boolean('ticket_status_announcements')
                ->default(false)
                ->after('ticket_announcements');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('ticket_status_announcements');
        });
    }
};
