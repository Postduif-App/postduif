<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A secret link that belongs to nobody in particular.
     *
     * The table was written around a secret being handed to one person in one
     * channel, and both of those turn out to be the narrower case. Somebody who
     * just needs a link — to paste into a mail, to read out over the phone, to
     * hand to a customer who is in no channel at all — was left making a channel
     * announcement they did not want.
     *
     * So both become optional, and they turn out to be optional for different
     * reasons:
     *
     * - channel_id null means there was no announcement. Nothing was said in
     *   any room, so nothing has to be taken back if the link is withdrawn.
     * - recipient_id null means nobody was named. The link is the credential,
     *   which was already true — naming a recipient was a label on the card, not
     *   a lock — so a secret with no name attached loses nothing but the card.
     *
     * Nothing about the secret itself changes: the ciphertext is still
     * unreadable here, and it is still readable exactly once.
     */
    public function up(): void
    {
        Schema::table('sent_secrets', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->change();
            $table->foreignId('recipient_id')->nullable()->change();
        });
    }

    /**
     * Deliberately not reversible in the strict sense.
     *
     * Going back would mean giving a channel and a recipient to rows that never
     * had either, and there is nothing to derive them from. Rather than invent
     * values, this drops the rows that could not exist under the old shape —
     * which is the honest reading of "undo this migration", and safe because a
     * standalone secret is by definition one nobody announced anywhere.
     */
    public function down(): void
    {
        // The rows that could not exist under the old shape, removed before the
        // columns refuse them. Losing them is the point rather than a side
        // effect: there is nothing to derive a channel or a recipient from, and
        // inventing one would be worse than dropping a link nobody announced.
        DB::table('sent_secrets')
            ->whereNull('channel_id')
            ->orWhereNull('recipient_id')
            ->delete();

        Schema::table('sent_secrets', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable(false)->change();
            $table->foreignId('recipient_id')->nullable(false)->change();
        });
    }
};
