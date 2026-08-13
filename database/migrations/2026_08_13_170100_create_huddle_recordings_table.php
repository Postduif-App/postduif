<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audio of a huddle, and what was said in it.
     *
     * ULID-keyed, and not because it is fashionable: the media table is
     * ulidMorphs — see its migration, which chose that for messages — so
     * anything that hangs a file off medialibrary has to be keyed the same way
     * or the morph column cannot hold it.
     *
     * One row per recording rather than per huddle. Browsers record locally and
     * upload when they stop, and somebody whose laptop lid closes halfway
     * through leaves a fragment worth keeping — a single row per huddle would
     * have to choose which fragment was the real one.
     *
     * The transcript is a column rather than a file, because it is text that is
     * read, searched and quoted. A file would put every one of those behind a
     * download.
     */
    public function up(): void
    {
        Schema::create('huddle_recordings', function (Blueprint $table) {
            $table->ulid('id')->primary();

            $table->foreignId('huddle_id')->constrained()->cascadeOnDelete();

            /*
             * Whose browser made it. Nullable because they can leave the
             * workspace afterwards and the recording should outlive them —
             * it is the channel's record of a conversation, not that person's
             * file.
             */
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('duration_seconds')->nullable();

            $table->text('transcript')->nullable();
            $table->timestamp('transcribed_at')->nullable();

            /*
             * Why it did not work, in the transcriber's own words. Kept for the
             * same reason the mail settings keep their last error: "did this
             * ever work" is the question somebody actually has, and a flash
             * message that disappeared cannot answer it.
             */
            $table->text('transcription_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('huddle_recordings');
    }
};
