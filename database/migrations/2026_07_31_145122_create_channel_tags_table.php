<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tags belong to the workspace, not to a channel.
     *
     * The point of them is that the same tag hangs on several channels — that
     * is what makes "stuur dit naar alles met #klant" possible later. A tag
     * stored per channel would be a label nobody could group by.
     *
     * The slug is what the uniqueness is on: "Klant" and "klant" are one tag
     * that somebody typed twice, and letting both exist gives you two lists
     * where you meant one. The name keeps the spelling it was created with, so
     * a tag still reads the way its author wrote it.
     */
    public function up(): void
    {
        Schema::create('channel_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 40);
            $table->timestamps();

            $table->unique(['workspace_id', 'slug']);
        });

        Schema::create('channel_channel_tag', function (Blueprint $table) {
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_tag_id')->constrained()->cascadeOnDelete();

            // No id of its own: a channel either carries a tag or it does not,
            // and the pair is the whole fact. The primary key is what stops the
            // same tag being hung on twice.
            $table->primary(['channel_id', 'channel_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_channel_tag');
        Schema::dropIfExists('channel_tags');
    }
};
