<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a workspace wants to hear about its contracts.
     *
     * The mirror image of the webhooks table beside it. That one is a door
     * somebody else posts through; this one is an address we post to — which
     * turns every assumption around. There the credential is a token we mint
     * and check; here it is a secret we share and sign with. There the danger
     * is somebody posting rubbish into a channel; here it is this server being
     * talked into fetching an address on the inside of somebody's network, which
     * is why every URL that lands in this table has been past GuardOutboundUrl
     * on the way in and is checked again before every delivery.
     *
     * A subscription per address rather than one row per workspace with a list
     * of addresses on it: two systems that both want to hear about contracts —
     * a CRM and an accounting package — have nothing to do with each other, and
     * one being switched off, rotated or found to be dead must say nothing about
     * the other.
     */
    public function up(): void
    {
        Schema::create('contract_webhooks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * What a person calls it. Purely for the beheerder's own list: two
             * URLs at the same host differ in a path somebody has to squint at,
             * and "Boekhouding" is what makes the row on the screen readable.
             */
            $table->string('name');

            /*
             * Deliberately generous. A webhook address is somebody else's, and
             * signed-URL style paths with a token in the query run long — a
             * column sized to what looks reasonable is a column that truncates
             * the one address a workspace actually wanted to use.
             */
            $table->string('url', 2048);

            /*
             * The shared secret, encrypted at rest.
             *
             * It cannot be hashed the way an incoming token is: signing a body
             * needs the secret itself in hand, so there is nothing one-way to
             * store. Encrypted rather than plain means a copied database row is
             * not enough on its own — the APP_KEY has to come with it — and it
             * is also what lets the screen show the secret again to somebody who
             * has to paste it into the receiving system. Text rather than a
             * bounded string because the ciphertext is several times the length
             * of what it hides.
             */
            $table->text('secret');

            /*
             * Which of the three this address wants: signed, declined,
             * completed. A list rather than three boolean columns, because a
             * fourth kind of news is a thing this feature will grow and the
             * shape should not need a migration to hold it.
             *
             * jsonb rather than json: Postgres stores it decomposed, so
             * "which subscriptions want this event" can one day be asked with a
             * containment operator against an index instead of read out into
             * PHP. Today the listener does read them out — there are a handful
             * per workspace — and this leaves that door open at no cost.
             */
            $table->jsonb('events');

            /*
             * Who set it up. Nullable and nullOnDelete: the person who wired an
             * integration up leaves, and the integration keeps working — a
             * subscription that vanished with its author would take a
             * workspace's deliveries down for a reason nobody could see.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The last thing that happened, kept as three columns rather than a
             * delivery log.
             *
             * A log would be the better answer to "why did the CRM not get it",
             * and it is a table that grows without bound for a feature whose
             * whole traffic is a handful of rows a day. These three answer the
             * question people actually ask — "is dit adres nog in leven" — and
             * they answer it in one row on the screen with no pruning to write.
             *
             * last_status is a smallint because it is an HTTP status and
             * nullable because a delivery can fail without ever getting one: a
             * name that does not resolve, a connection refused, an address the
             * guard would not go to.
             */
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->smallInteger('last_status')->nullable();

            /*
             * Switched off rather than deleted. An integration that is being
             * repaired at the far end should stop receiving without anybody
             * having to write the address and its secret down first, and the
             * moment it was switched off is worth as much as the fact.
             */
            $table->timestamp('disabled_at')->nullable();

            $table->timestamps();

            /*
             * The index the listener actually uses: every delivery starts with
             * "the live subscriptions of this workspace". The events column is
             * not part of it — a workspace has a handful of these, and filtering
             * three names in PHP is cheaper than the index that would save it.
             */
            $table->index(['workspace_id', 'disabled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_webhooks');
    }
};
