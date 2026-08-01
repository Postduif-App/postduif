<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Groups somebody made for themselves in their own sidebar.
     *
     * Per member and per workspace, not per workspace alone: how you order your
     * work is not how your colleague orders theirs, and a shared set of groups
     * would be one person deciding for everybody. The same reasoning as muting
     * and favourites, which also live on the membership rather than on the
     * channel.
     *
     * Tags are the other thing and stay the other thing: a tag describes the
     * channel — "klant", "escalatie" — and everybody sees the same ones. A
     * section describes your desk.
     */
    public function up(): void
    {
        Schema::create('channel_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');

            // Where it sits among the others. Kept rather than sorted by name:
            // the order is part of what somebody arranged.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'workspace_id', 'name']);
            $table->index(['user_id', 'workspace_id', 'position']);
        });

        /*
         * Which channels sit in which section.
         *
         * A channel belongs to at most one section per member — a channel in
         * two groups is in neither, as far as finding it back goes — which the
         * unique index on the channel enforces.
         */
        Schema::create('channel_channel_section', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->unique(['channel_section_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_channel_section');
        Schema::dropIfExists('channel_sections');
    }
};
