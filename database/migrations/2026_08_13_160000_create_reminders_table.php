<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Herinner me hieraan om negen uur."
     *
     * A row of its own rather than a column on the inbox item it eventually
     * becomes, because the two exist at different times: a reminder is made now
     * for later, and until later arrives there is nothing an inbox should show.
     * Folding them together would mean either an inbox that has to filter out
     * the future on every read, or a reminder that cannot be made at all until
     * it is already due.
     *
     * Always about a message. A free-standing "remind me to call the plumber"
     * is a to-do list, and this application already has one of those with a
     * status, an assignee and a channel behind it — see tickets. What is
     * missing is the small thing: something said in a conversation that you
     * cannot deal with now.
     */
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * ULID, because messages are. cascadeOnDelete rather than
             * nullOnDelete: a reminder about a message that has been withdrawn
             * has nothing left to point at, and firing it would send somebody
             * to an empty space with no way of knowing what was there.
             */
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();

            /*
             * Denormalised off the message, because the inbox row this becomes
             * needs it and looking it up at delivery would be a join per
             * reminder for a column that cannot change — a message does not
             * move between channels.
             */
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            /*
             * Why you wanted reminding, in your own words. Optional and often
             * empty: for most reminders the message itself is the whole of the
             * reason, and a required field there would be a form standing
             * between somebody and a single click.
             */
            $table->string('note')->nullable();

            $table->timestamp('remind_at');

            /*
             * Claimed when the sweep picks it up, so a second run cannot fire
             * the same reminder twice. The same shape scheduled messages use,
             * and for the same reason: the schedule is not a promise to run
             * once.
             */
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();

            /*
             * What the sweep asks for, in the order it asks: the ones not yet
             * delivered whose moment has passed.
             */
            $table->index(['delivered_at', 'remind_at']);
        });

        /*
         * One pending reminder per person per message.
         *
         * Partial, so that only the undelivered ones are held to it: setting a
         * second reminder on the same message tomorrow is an ordinary thing to
         * want, and the one that already fired should not be in the way of it.
         *
         * It is also what makes "herinner me hier nogmaals aan" a harmless
         * repeat click rather than two rows that both go off.
         */
        DB::statement('create unique index reminders_pending_unique
            on reminders (user_id, message_id) where delivered_at is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
