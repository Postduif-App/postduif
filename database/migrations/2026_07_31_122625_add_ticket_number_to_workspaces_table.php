<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ticket numbers are handed out per workspace, so the counter lives here.
     *
     * A counter rather than MAX(number) + 1 over the tickets themselves: a
     * deleted ticket must not hand its number to the next one, because somebody
     * who says "even over #42" has to keep meaning the same ticket forever.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('next_ticket_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('next_ticket_number');
        });
    }
};
