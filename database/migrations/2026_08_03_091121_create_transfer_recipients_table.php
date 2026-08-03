<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The people a transfer was addressed to, each with a link of their own.
     *
     * The narrowest of the three audiences, and the only one where forwarding
     * shows. An open link that reaches five people is indistinguishable from
     * one that reached one; five separate tokens are five separate counters, so
     * the sender can see that the link they mailed to one address was used
     * three times and ask why.
     *
     * A table rather than a column of addresses for the reason
     * channel_invite_link is one: each row carries its own token, its own count
     * and its own withdrawal, and none of that fits in a json list.
     */
    public function up(): void
    {
        Schema::create('transfer_recipients', function (Blueprint $table) {
            $table->id();

            // foreignUlid rather than foreignId: transfers are ULID-keyed, and
            // a bigint here would not hold the key it is pointing at.
            $table->foreignUlid('transfer_id')->constrained()->cascadeOnDelete();

            $table->string('email');

            /*
             * What actually opens the door. Separate per recipient, which is
             * the whole point: the transfer's own token opens nothing when this
             * audience is chosen, so a link that leaks is traceable to whom it
             * was given.
             */
            $table->string('token', 64)->unique();

            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            // Withdrawing one address without touching the others — a mistyped
            // address should not cost the other four recipients their link.
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // The same address twice on one transfer is two links to the same
            // inbox, which is confusion rather than a feature.
            $table->unique(['transfer_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_recipients');
    }
};
