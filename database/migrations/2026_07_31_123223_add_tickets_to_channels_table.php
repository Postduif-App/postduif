<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('ticket_policy')->default('disabled')->after('posting_policy');

            /**
             * Whether a new or closed ticket also says so in the conversation.
             *
             * On by default wherever tickets are switched on: somebody who only
             * follows the chat would otherwise never learn that a ticket was
             * raised, and finding that out late is the failure this whole
             * feature exists to prevent.
             */
            $table->boolean('ticket_announcements')->default(true)->after('ticket_policy');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['ticket_policy', 'ticket_announcements']);
        });
    }
};
