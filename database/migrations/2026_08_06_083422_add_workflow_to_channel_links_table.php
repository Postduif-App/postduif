<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A button in the bar may now start a workflow instead of opening a URL.
     *
     * The same table rather than a second one, because the bar is one row of
     * buttons: they share a label, an order and the panel they are managed in,
     * and two tables would mean two lists that have to be interleaved by
     * position every time the bar is drawn.
     *
     * So url becomes nullable and workflow_id joins it, with exactly one of the
     * two filled — enforced by the check below rather than only in the request,
     * because a button that neither goes anywhere nor starts anything is not a
     * thing the bar can draw at all.
     *
     * Cascade rather than null on delete: a button whose workflow is gone does
     * nothing, and leaving it there is leaving a label people press twice
     * before deciding the chat is broken.
     */
    public function up(): void
    {
        Schema::table('channel_links', function (Blueprint $table) {
            $table->foreignId('workflow_id')
                ->nullable()
                ->after('channel_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('channel_links', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->change();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE channel_links
            ADD CONSTRAINT channel_links_target_check
            CHECK ((url IS NULL) <> (workflow_id IS NULL))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE channel_links DROP CONSTRAINT channel_links_target_check');

        Schema::table('channel_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
        });

        /*
         * Rows whose url was null can only be workflow buttons, and their
         * workflow has just gone. Nothing sensible is left to put in the column,
         * so they go with it rather than being handed a placeholder URL that
         * somebody would click.
         */
        DB::table('channel_links')->whereNull('url')->delete();

        Schema::table('channel_links', function (Blueprint $table) {
            $table->string('url', 2048)->nullable(false)->change();
        });
    }
};
