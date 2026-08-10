<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The same full-text arrangement the messages have, so a search over a
     * workspace answers one way rather than two.
     *
     * Over the title and the flattened text, not the document. body_text exists
     * precisely so that nothing outside the editor has to know what a block
     * looks like, and a tsvector built by walking JSON would have to relearn
     * that every time a plugin is added.
     *
     * The title is weighted above the body. Somebody searching "draaiboek" is
     * far more often looking for the document called that than for one of the
     * forty that mention the word — which is the opposite of how it works for
     * messages, where there is no title to weigh.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE documents
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('simple', title), 'A') ||
                setweight(to_tsvector('simple', body_text), 'B')
            ) STORED
        SQL);

        DB::statement('CREATE INDEX documents_search_vector_index ON documents USING GIN (search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_search_vector_index');
        DB::statement('ALTER TABLE documents DROP COLUMN IF EXISTS search_vector');
    }
};
