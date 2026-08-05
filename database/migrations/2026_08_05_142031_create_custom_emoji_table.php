<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pictures a workspace makes up for itself.
     *
     * A row per emoji rather than a bag on the workspace, because each one has
     * a file behind it, somebody who uploaded it and a date — and because the
     * name has to be unique per workspace, which an index says once and a bag
     * would have to be talked into on every write.
     *
     * The file lives on the private disk beside the avatars rather than in the
     * media library: that table keys its owners with a ULID for the sake of
     * Message, and everything under workspace settings is an ordinary integer.
     */
    public function up(): void
    {
        Schema::create('custom_emoji', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * What gets typed between the colons. Stored without them: the
             * colons are punctuation in the message rather than part of the
             * name, so the unique index sits on the thing people actually pick.
             *
             * Thirty, not thirty-two: a reaction stores the whole shortcode,
             * colons and all, in a column of thirty-two — see
             * CustomEmoji::NAME_PATTERN.
             */
            $table->string('name', 30);

            $table->string('path');

            /*
             * What to hand it back as. A PNG becomes a webp on the way in, but
             * an animated GIF is kept as it arrived — converting it would
             * quietly return a still of the first frame.
             */
            $table->string('mime', 32);

            /*
             * Who put it there, for the list. Null once they leave: an emoji
             * belongs to the workspace and outlives whoever uploaded it.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_emoji');
    }
};
