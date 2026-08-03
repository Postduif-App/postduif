<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files put aside for somebody to fetch, with a link that stands for them.
     *
     * Keyed by ULID, and not for the usual reason: the media table keys
     * model_id as character(26), which is what ruled the library out for ticket
     * attachments and avatars. A transfer is the one other thing here that
     * carries a pile of files, so it is worth being shaped to fit the table
     * that already knows how to hold them.
     *
     * The three ways a link can stop working are stored apart, as on
     * invite_links: "this has been used up" and "this was withdrawn" are
     * different things to say to whoever is holding it.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // The sender is kept after they leave — a colleague's last day
            // should not break a link a customer is waiting on. The files go
            // when the transfer expires, which is soon enough either way.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The secret. Separate from the id even though the id is already
             * unguessable: an id turns up in logs, in payloads and in error
             * reports, and this is the whole of the proof that you were meant
             * to have these files.
             */
            $table->string('token', 64)->unique();

            // What the sender called it, and anything they wanted to say with
            // it. Both optional: the files usually say enough by themselves.
            $table->string('title')->nullable();
            $table->text('message')->nullable();

            /*
             * Required, unlike the date on an invite link. A link that costs
             * nothing to keep may live forever; a link holding gigabytes may
             * not. Expiry is what hands the disk back — see the prune command —
             * so there is no value here that means "never".
             */
            $table->timestamp('expires_at');

            // Null does mean unbounded here: how often something may be
            // fetched is a choice, and it costs nothing to leave open.
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('downloads')->default(0);

            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // The two lists there are: what a workspace has out, and what one
            // person has out.
            $table->index(['workspace_id', 'expires_at']);
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
