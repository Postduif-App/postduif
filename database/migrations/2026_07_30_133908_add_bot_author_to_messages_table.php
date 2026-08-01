<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message posted through a webhook has no member behind it, so user_id
     * gives way and a bot name takes its place.
     *
     * The name is copied onto the message rather than read from the webhook.
     * Renaming a webhook then says something about what it does next, not about
     * what it already said — and a message keeps its author even after the
     * webhook that sent it is gone.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('webhook_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
            $table->string('bot_name')->nullable()->after('webhook_id');
        });

        // bot_name is the discriminator, not webhook_id: a deleted webhook
        // leaves its messages behind with webhook_id nulled, and those are
        // still bot messages.
        DB::statement(<<<'SQL'
            ALTER TABLE messages
            ADD CONSTRAINT messages_author_is_member_or_bot
            CHECK ((user_id IS NULL) <> (bot_name IS NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_author_is_member_or_bot');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webhook_id');
            $table->dropColumn('bot_name');
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
