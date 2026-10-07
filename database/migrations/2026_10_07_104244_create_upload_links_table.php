<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A link somebody outside can use to hand files to us.
     *
     * The mirror of the transfers table: a transfer is files we put aside for
     * somebody to fetch, this is room we put aside for somebody to fill. The
     * same three ways a link stops working are stored apart, for the same
     * reason — the person holding it deserves to hear which one it was.
     *
     * Keyed by ULID like a transfer, though here the files hang on the
     * submissions rather than on this row: see that table.
     */
    public function up(): void
    {
        Schema::create('upload_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // Kept when the member leaves: a customer halfway through sending
            // in their paperwork should not find the door shut on them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Where the news goes when somebody sends something in. Optional,
             * and nulled rather than cascaded when the channel goes: without it
             * the mail is the whole of the notification, which is a smaller
             * thing than a broken link.
             */
            $table->foreignId('notify_channel_id')->nullable()->constrained('channels')->nullOnDelete();

            // The secret, apart from the id for the reason a transfer's is.
            $table->string('token', 64)->unique();

            // Required, unlike on a transfer: there are no files yet to say
            // what this is about, so the title is what the uploader reads.
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('password')->nullable();

            /*
             * Required. A link anybody may drop gigabytes into must not stay
             * open forever, and expiry is what hands the disk back.
             */
            $table->timestamp('expires_at');

            // How many times somebody may send something in. Null is as often
            // as they like, until the size ceiling or the date stops them.
            $table->unsignedInteger('max_uploads')->nullable();
            $table->unsignedInteger('uploads')->default(0);

            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'expires_at']);
            $table->index(['created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_links');
    }
};
