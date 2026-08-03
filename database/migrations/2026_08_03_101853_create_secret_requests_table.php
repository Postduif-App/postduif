<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asking somebody for values you must not have sent to you in a chat.
     *
     * The problem this exists for: a customer has to hand over a database
     * password or an API key, and the obvious ways — a message, a mail — leave
     * it sitting in somebody's history forever, readable by everyone in the
     * channel and by anyone who later joins it.
     *
     * What is stored, and what that is worth
     * -------------------------------------
     * The values are encrypted with the application key. Be clear about what
     * that buys: a stolen database dump is useless without APP_KEY, which is
     * the realistic loss. It buys nothing against somebody who is already on
     * the server, because the key is there too. That is exactly why the expiry
     * date below is required rather than optional — a secret that is deleted on
     * time is protected by something stronger than a cipher.
     *
     * Keyed by ULID like Message and Transfer: this lands in a conversation and
     * its id travels in URLs.
     */
    public function up(): void
    {
        Schema::create('secret_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Where it was asked. A channel or a DM — the epic wants both, and
             * a DM is a channel here, so nothing special is needed for it.
             */
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            /*
             * The one person who may ever read the answers.
             *
             * Not nullable, unlike the sender of a transfer: a transfer without
             * a sender is still a working link, while a secret nobody can read
             * is only a liability. If the requester's account goes, the request
             * goes with it.
             */
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            /*
             * Required, for the reason in the note above: this is the limit
             * that actually protects the values, so there is no value here
             * meaning "never".
             */
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();

            /*
             * The sharpest setting there is, and off unless asked for: the
             * value is deleted the moment the requester has read it. Right for
             * a password you are going to paste into a server and never need
             * again, wrong for one you will look up next week — so it is a
             * choice rather than the rule.
             */
            $table->boolean('burn_after_reading')->default(false);

            $table->timestamps();

            $table->index(['workspace_id', 'expires_at']);
            $table->index(['created_by', 'created_at']);
        });

        /*
         * What is being asked for. A table rather than a json list because each
         * one carries its own answer, its own "who filled this in", and its own
         * moment — none of which fits in a column of names.
         */
        Schema::create('secret_request_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('secret_request_id')->constrained()->cascadeOnDelete();

            // What the customer recognises: DB_PASSWORD, MAIL_USERNAME.
            $table->string('name');

            // Anything the requester wants to say about this one in particular,
            // where the name is not self-explanatory.
            $table->string('hint')->nullable();

            $table->unsignedSmallInteger('position')->default(0);

            // The same key twice in one request is two boxes for one answer.
            $table->unique(['secret_request_id', 'name']);
        });

        Schema::create('secret_values', function (Blueprint $table) {
            $table->id();

            /*
             * One value per key, enforced here rather than in PHP. "Fill it in
             * once and never see it again" is the whole promise, and a promise
             * kept only by a check in application code is one that two browser
             * tabs can break.
             */
            $table->foreignId('secret_request_key_id')->unique()
                ->constrained()->cascadeOnDelete();

            /*
             * The ciphertext. Never cast to 'encrypted' on the model, so that
             * reading it is always something somebody wrote out on purpose —
             * see SecretValue::reveal().
             */
            $table->text('value');

            // Who answered, and when. Null for somebody without an account, who
            // cannot reach this at all today but might later.
            $table->foreignId('filled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('filled_at');

            // When the requester read it, so there is at least a trace of the
            // one moment the value came back out.
            $table->timestamp('revealed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_values');
        Schema::dropIfExists('secret_request_keys');
        Schema::dropIfExists('secret_requests');
    }
};
