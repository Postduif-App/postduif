<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a document looked like before somebody changed it.
     *
     * The one thing a document had no answer for. Deleting one is soft and can
     * be undone; overwriting one could not be, and overwriting is how a document
     * is actually lost — a stray select-all, a paste over everything, autosave
     * firing two seconds later. The version column on documents counts saves so
     * that two people cannot silently overwrite each other, but it keeps
     * nothing; when the check passes, the old text is simply gone.
     *
     * A row here is the body as it stood before a save, not after: what is
     * stored is the thing somebody would want back, and the current version is
     * never duplicated — it is already on the document.
     */
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            /*
             * Whoever wrote the text in this row — which is the document's
             * previous editor, not the one whose save triggered the snapshot.
             * Nullable and kept when they leave: "restored to the version from
             * before Tuesday" survives the person who typed it.
             */
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * The whole body rather than a diff. Diffs are smaller and make
             * both restoring and showing harder, and for a mechanism whose
             * entire job is to be trusted in a bad moment, "you can read what
             * it does" is worth more than the disk it saves. The cap in
             * PruneDocuments is what keeps that honest.
             */
            $table->json('body');
            $table->longText('body_text');

            // No updated_at: a revision is a fact about a moment and is never
            // edited. The index is what the history panel orders by.
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
