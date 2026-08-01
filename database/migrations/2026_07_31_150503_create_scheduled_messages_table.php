<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A message somebody wrote now to be said later.
     *
     * Deliberately its own table rather than a nullable column on messages. A
     * scheduled message is not a message yet: it must not appear in a channel,
     * count towards unread, be searchable, be reacted to, or be quoted — and
     * every one of those would have to learn to exclude it. When its moment
     * comes it becomes a real message through the ordinary SendMessage, so
     * mentions, webhooks and broadcasts behave identically.
     *
     * The author is kept even when the channel goes: sent_at and failed_at are
     * the record of what happened, and cascading the channel away would take
     * the evidence with it. The channel does cascade — there is nowhere left to
     * say it.
     */
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('send_at');
            $table->timestamp('sent_at')->nullable();
            /*
             * Why it did not go out, kept rather than thrown away: a message
             * that silently never arrives is worse than one that says it
             * failed. The row stays in the overview, marked.
             */
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            // The dispatcher's whole question: what is due and not yet gone.
            $table->index(['send_at', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
