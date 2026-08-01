<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files hung on a ticket comment.
     *
     * A table of its own rather than the media library the messages use, and
     * the reason is worth writing down: that table keys model_id as
     * character(26), sized for the ULIDs a Message has. A ticket comment has an
     * ordinary integer id, which does not fit and does not compare — the same
     * clash that ruled the library out for avatars.
     */
    public function up(): void
    {
        Schema::create('ticket_comment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_comment_id')->constrained()->cascadeOnDelete();

            // Where it sits. The disk is recorded rather than assumed, so
            // moving storage later does not orphan what is already here.
            $table->string('disk');
            $table->string('path');

            // What it was called when it arrived, which is what it should be
            // called again on the way out — the stored name is a random one.
            $table->string('name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comment_attachments');
    }
};
