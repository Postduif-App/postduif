<?php

use App\Enums\InboxItemType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mentions table was always an inbox with one hardcoded reason.
     *
     * It already carried the thing that makes an inbox more than a list — a
     * read stamp — and the sidebar badge already runs off it. Generalising it
     * keeps both, where a second table beside it would have meant two places
     * to mark something read and two orderings to reconcile.
     *
     * Note that the index names dropped below still say "mentions": Postgres
     * carries constraint names through a table rename, so they have to go
     * under the names they were created with, not the ones Laravel would
     * guess from the new table.
     */
    public function up(): void
    {
        Schema::rename('mentions', 'inbox_items');

        Schema::table('inbox_items', function (Blueprint $table) {
            /*
             * Defaulted only so the existing rows land on the right value —
             * every one of them is a mention. The default comes off again at
             * the bottom of this migration, because a row whose reason was
             * forgotten should be an error rather than quietly a mention.
             */
            $table->string('type')->default(InboxItemType::Mention->value);

            /*
             * Who caused it, so the inbox can say "Fenna antwoordde" rather
             * than leaving somebody to open the row to find out. Nullable
             * because a webhook has no member behind it, and nullOnDelete
             * because somebody who leaves should not take the row with them —
             * what was said still stands.
             */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * A poll is not reachable from a message: the link lives in the
             * message body as a URL, so there is nothing to join on. Pointing
             * at the poll directly is what lets a vote row render at all.
             *
             * Two nullable columns rather than a polymorphic pair, because
             * these are two known kinds and not an open set — a morph column
             * would buy nothing here but a join that resolves to one of two
             * tables we could have named outright.
             */
            $table->foreignUlid('poll_id')->nullable()->constrained()->cascadeOnDelete();

            $table->dropUnique('mentions_message_id_user_id_unique');
            $table->dropIndex('mentions_user_id_read_at_index');

            // The only question the inbox page asks: this member's rows,
            // unread first and newest first. The trailing id is what lets the
            // sort be served by the index rather than by a pass over the
            // matches.
            $table->index(['user_id', 'read_at', 'id']);
        });

        // A vote row has a poll and no message. Raw rather than change(), so
        // that dropping the NOT NULL cannot take the foreign key with it.
        DB::statement('alter table inbox_items alter column message_id drop not null');

        /*
         * The collapse key, and the reason it is two partial indexes rather
         * than one unique over all four columns: Postgres counts NULLs as
         * distinct, so a single index spanning both subject columns would let
         * every mention row through unchecked — exactly the rows that most
         * need deduplicating.
         *
         * This is what makes updateOrCreate the whole of the fan-out. Twenty
         * replies in one thread become one row that keeps being bumped, not
         * twenty lines somebody has to scroll past.
         */
        DB::statement('create unique index inbox_items_message_subject_unique
            on inbox_items (user_id, type, message_id) where message_id is not null');

        DB::statement('create unique index inbox_items_poll_subject_unique
            on inbox_items (user_id, type, poll_id) where poll_id is not null');

        DB::statement('alter table inbox_items alter column type drop default');
    }

    public function down(): void
    {
        DB::statement('drop index if exists inbox_items_message_subject_unique');
        DB::statement('drop index if exists inbox_items_poll_subject_unique');

        // A row without a message has no place in a mentions table.
        DB::table('inbox_items')->whereNull('message_id')->delete();

        DB::statement('alter table inbox_items alter column message_id set not null');

        Schema::table('inbox_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at', 'id']);
            $table->dropConstrainedForeignId('actor_id');
            $table->dropConstrainedForeignId('poll_id');
            $table->dropColumn('type');

            $table->unique(['message_id', 'user_id'], 'mentions_message_id_user_id_unique');
            $table->index(['user_id', 'read_at'], 'mentions_user_id_read_at_index');
        });

        Schema::rename('inbox_items', 'mentions');
    }
};
