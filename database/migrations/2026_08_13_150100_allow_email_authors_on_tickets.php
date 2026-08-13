<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A ticket that walked in through the letterbox has no member behind it.
     *
     * The same shape messages already use for a webhook: the member column
     * gives way, and something that says who it was takes its place, with a
     * check constraint holding the pair to exactly one of the two. It is worth
     * copying rather than inventing a second answer — anybody who has read how
     * a bot message is stored can read this without being told.
     *
     * The address is copied onto the row rather than looked up later. Somebody
     * who writes in is not an account and may never become one, and a ticket
     * that only knew "an e-mail did this" would be a ticket nobody could
     * answer.
     *
     * mail_message_id is the other half of answering: it is what a reply names
     * in its In-Reply-To header, and it is how the second mail in a
     * conversation finds the ticket the first one opened. Indexed because that
     * lookup happens on every delivery.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('opened_by')->nullable()->change();
            $table->string('sender_email')->nullable()->after('opened_by');
            /*
             * The name as the sender's own mail client wrote it, which is often
             * absent and is never to be trusted as an identity — it is a label
             * on a row, and the address beside it is the thing that is actually
             * known.
             */
            $table->string('sender_name')->nullable()->after('sender_email');
            $table->string('mail_message_id')->nullable()->after('sender_name')->index();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tickets
            ADD CONSTRAINT tickets_opened_by_member_or_email
            CHECK ((opened_by IS NULL) <> (sender_email IS NULL))
        SQL);

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('sender_email')->nullable()->after('user_id');
            $table->string('sender_name')->nullable()->after('sender_email');
            $table->string('mail_message_id')->nullable()->after('sender_name')->index();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ticket_comments
            ADD CONSTRAINT ticket_comments_author_is_member_or_email
            CHECK ((user_id IS NULL) <> (sender_email IS NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tickets DROP CONSTRAINT tickets_opened_by_member_or_email');
        DB::statement('ALTER TABLE ticket_comments DROP CONSTRAINT ticket_comments_author_is_member_or_email');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['sender_email', 'sender_name', 'mail_message_id']);
            $table->foreignId('opened_by')->nullable(false)->change();
        });

        Schema::table('ticket_comments', function (Blueprint $table) {
            $table->dropColumn(['sender_email', 'sender_name', 'mail_message_id']);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
