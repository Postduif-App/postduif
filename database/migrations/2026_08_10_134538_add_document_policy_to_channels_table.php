<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('document_policy')->default('disabled')->after('ticket_status_announcements');

            /**
             * Whether a new document also says so in the conversation.
             *
             * On by default wherever documents are switched on, for the reason a
             * document exists at all: it is the channel's shared memory, and one
             * nobody was told about is a document of one.
             *
             * Only creating and renaming announce. Saving happens by itself
             * every few seconds while somebody types, and a channel that
             * reported each of those would be unusable.
             */
            $table->boolean('document_announcements')->default(true)->after('document_policy');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['document_policy', 'document_announcements']);
        });
    }
};
