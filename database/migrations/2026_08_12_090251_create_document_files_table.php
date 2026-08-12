<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Files placed inside a document: the images, and everything else somebody
     * drops between two paragraphs.
     *
     * A table of its own rather than the media library, for the reason the
     * ticket comments have one — see that migration. The library keys model_id
     * as character(26), sized for the ULIDs a Message carries, and a document
     * has an ordinary integer id which neither fits nor compares.
     *
     * What is different here, and what the extra columns are for: a message is
     * written once and its files come with it, while a document is edited for
     * months. A file is uploaded before the document that mentions it is saved,
     * and may stop being mentioned without anybody deleting anything. So a row
     * has to be able to say "nothing points at me yet" without that meaning
     * "throw me away" — see ReconcileDocumentFiles for the hour of grace that
     * gap needs.
     */
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            /*
             * Who put it there. Kept when they leave, unlike the file itself:
             * the document outlives the membership, and a picture that vanished
             * because somebody changed jobs is a hole in the page nobody can
             * explain.
             */
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Where it sits. The disk is recorded rather than assumed, so
            // moving storage later does not orphan what is already here.
            $table->string('disk');
            $table->string('path');

            // What it was called when it arrived, which is what it should be
            // called again on the way out — the stored name is a random one.
            $table->string('name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');

            /*
             * Only for images, and only so the editor can hold the right amount
             * of space before the bytes arrive. Nullable because a PDF has no
             * answer to this question.
             */
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
