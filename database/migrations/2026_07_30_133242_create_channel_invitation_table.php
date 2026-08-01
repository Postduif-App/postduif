<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The channels an invitation opens up.
     *
     * Only guests need this: they get no workspace to browse, so the channels
     * they may see have to be named up front. A table rather than a column on
     * invitations, so a channel that is deleted before the invitation is
     * accepted drops out of it instead of leaving behind an id that resolves
     * to nothing.
     */
    public function up(): void
    {
        Schema::create('channel_invitation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invitation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            $table->unique(['invitation_id', 'channel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_invitation');
    }
};
