<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Something one member set aside to come back to.
     *
     * Deliberately not a pin: a pin is editorial and belongs to the channel,
     * where this belongs to a person and nobody else can see it. That is also
     * why there is no "pinned_by" equivalent — the owner is the whole row.
     *
     * The channel is stored alongside the message rather than read through it,
     * so the list can be scoped to what somebody may still see without joining
     * messages for every row.
     */
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Saving the same message twice is the same act, not a second one.
            $table->unique(['user_id', 'message_id']);

            // The list is "mine, most recently saved first".
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
