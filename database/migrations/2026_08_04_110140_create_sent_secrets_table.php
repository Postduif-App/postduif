<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A secret sent to somebody, readable exactly once.
     *
     * The mirror of secret_requests: that one asks somebody for a value, this
     * one hands one over. Its own table rather than a direction flag on that
     * one, because almost nothing is shared — a request has keys, positions and
     * a form; this has one value, one reader and one moment.
     *
     * What is stored, and what that is worth
     * -------------------------------------
     * Unlike secret_values, this application cannot read what is in here. The
     * ciphertext arrives already encrypted from the sender's browser and the key
     * never leaves it: it travels in the fragment of the link, which browsers do
     * not put in the request, so it reaches neither our access logs nor any
     * proxy in between.
     *
     * That is a stronger promise than secret_values makes, and it was chosen
     * deliberately — see the note on the encryption there. The price is that a
     * lost link is a lost secret, and that the server can never reconstruct the
     * full URL. That second point shapes the whole feature: the card in the
     * channel cannot carry the link, so the sender is shown it once and passes
     * it on themselves.
     *
     * Keyed by ULID like Message, Poll and SecretRequest: it lands in a
     * conversation and its id travels in a URL.
     */
    public function up(): void
    {
        Schema::create('sent_secrets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Where it was announced. A channel or a DM — a DM is a channel
            // here, so nothing special is needed for it.
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            /*
             * The sender. Cascade rather than the null a Poll allows its asker:
             * a secret whose sender is gone is nobody's to withdraw, and the
             * point of this feature is that it leaves no orphans lying about.
             */
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            /*
             * Who it is meant for. A label rather than a lock — the link is what
             * grants access, because the server has no key to check anybody
             * against. It is here so the card can say who is being waited on,
             * and so the channel has a record of who was handed what.
             */
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();

            // What the sender is handing over, in one line, said in the open.
            // "De staging-database" — never the value itself.
            $table->string('label');

            /*
             * The secret, encrypted in the sender's browser and unreadable here.
             * Base64 rather than binary so it survives every driver and every
             * dump unchanged; the size is what an AES-GCM payload of a password
             * or a key comes to, with room to spare.
             */
            $table->text('ciphertext');

            // The nonce, alongside rather than glued to the ciphertext: one
            // field meaning two things is one that gets split wrong later.
            $table->string('iv');

            /*
             * An optional second gate, checked here before the ciphertext is
             * handed out at all.
             *
             * Hashed like a password because that is what it is. Deliberately
             * not mixed into the key derivation, which would have been stronger
             * on paper: doing it here keeps a wrong guess distinguishable from
             * corrupt data, keeps the attempts throttleable, and — the one that
             * decides it — keeps a wrong guess from burning the secret. The
             * server gains nothing either way; without the fragment the
             * ciphertext stays shut to it.
             */
            $table->string('password_hash')->nullable();

            /*
             * Required, and for the same reason secret_requests.expires_at is:
             * a secret that is deleted on time is protected by something
             * stronger than any cipher. There is no value here meaning "never".
             */
            $table->timestamp('expires_at');

            /*
             * When it was read, which is also when the ciphertext was blanked.
             * Kept rather than deleting the row outright, so the sender can be
             * told it arrived and the card can stop inviting a second attempt.
             * There is nothing left to protect in a row whose ciphertext is
             * gone — see PruneSentSecrets for when the row itself goes.
             */
            $table->timestamp('revealed_at')->nullable();

            $table->timestamps();

            // How the pruner sweeps, and how the channel finds its own.
            $table->index(['workspace_id', 'expires_at']);
            $table->index(['recipient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_secrets');
    }
};
