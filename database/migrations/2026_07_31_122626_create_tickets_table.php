<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            // Unique per workspace rather than per channel: somebody who says
            // "even over #42" should not have to remember which channel that
            // was in.
            $table->unsignedInteger('number');

            $table->string('title');
            $table->text('body');
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');

            $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            /**
             * Where this ticket came from, when it was promoted out of a
             * message. Nulled rather than cascaded on delete: the ticket is a
             * record of its own and outlives the message that prompted it — the
             * link simply stops pointing anywhere.
             */
            $table->foreignUlid('source_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestamp('due_at')->nullable();

            /**
             * Filled the first time somebody other than the opener answers.
             * Recorded from the start even though nothing reads it yet: it
             * cannot be reconstructed afterwards, and it is the one number that
             * says whether this channel is actually being served.
             */
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'number']);
            $table->index(['channel_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
