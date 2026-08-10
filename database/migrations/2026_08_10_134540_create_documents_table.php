<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            // Unique per workspace rather than per channel, the same choice the
            // tickets made and for the same reason: a number people say out
            // loud has to mean one thing.
            $table->unsignedInteger('number');

            $table->string('title');

            /**
             * The document as the editor hands it over: a Yoopta content value,
             * which is a map of block id to block.
             *
             * Stored whole rather than as a row per block. Per-block rows would
             * only start paying off with per-block collaborative editing, and
             * until that exists they would buy nothing but a join and the
             * chance for the two representations to disagree.
             */
            $table->json('body');

            /**
             * The same document flattened to plain text, for searching.
             *
             * Denormalised on purpose. Searching inside a JSON document means
             * knowing its shape, and that shape belongs to the editor and moves
             * whenever a plugin is added. The editor can already flatten itself,
             * so the client sends this along and the search never has to learn
             * what a block looks like.
             */
            $table->longText('body_text');

            /**
             * Bumped on every save, and checked against what the client sent.
             *
             * Autosave fires while people type, so two editors in one document is
             * not an edge case but the ordinary Tuesday. Without this the second
             * save silently erases the first; with it, the second save can be
             * told what happened.
             */
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            /**
             * Nulled rather than cascaded: when the last editor's account goes,
             * the document stays and simply stops naming who touched it last.
             */
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();

            $table->unique(['workspace_id', 'number']);

            // The list a channel shows: its documents, newest edit first, with
            // the deleted ones already excluded.
            $table->index(['channel_id', 'deleted_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
