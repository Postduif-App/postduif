<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The buttons above a conversation: a label and somewhere to go.
     *
     * Gone with the channel, hence the cascade — a link to the customer's
     * planning has no meaning once the channel it hung above is deleted.
     *
     * Position is a column of its own rather than an ordering by id, because
     * the order is the point: whoever manages the channel decides which one
     * comes first, and creation order is not that.
     */
    public function up(): void
    {
        Schema::create('channel_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->string('url', 2048);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['channel_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_links');
    }
};
