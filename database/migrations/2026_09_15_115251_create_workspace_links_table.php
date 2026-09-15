<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The workspace's own buttons: a label and somewhere outside to go.
     *
     * The workspace-wide twin of channel_links, and deliberately the narrower
     * of the two — a button above a channel may also start a workflow, which
     * is something a conversation has context for and a sidebar menu does not.
     * Here a button is a link, and the column being non-nullable is what says
     * so.
     *
     * Position is a column rather than an ordering by id, for the same reason
     * as over there: the order is the point, and creation order is not it.
     */
    public function up(): void
    {
        Schema::create('workspace_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('label', 40);
            $table->string('url', 2048);
            /*
             * The picture on the row, or nothing. An emoji rather than a name
             * from an icon set: the workspace already has a picker for these
             * and a list of every emoji in the browser, so this costs no new
             * furniture — and a beheerder picking from a fixed set of twenty
             * lucide glyphs would sooner or later want the twenty-first.
             */
            $table->string('emoji', 16)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_links');
    }
};
