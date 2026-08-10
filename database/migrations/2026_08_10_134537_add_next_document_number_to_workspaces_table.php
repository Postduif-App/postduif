<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document numbers are handed out per workspace, so the counter lives here.
     *
     * Its own counter rather than sharing the ticket one. They are two different
     * things people refer to out loud, and a workspace where #7 is sometimes a
     * ticket and sometimes a document is a workspace where the number stops being
     * a way to point at anything.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('next_document_number')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('next_document_number');
        });
    }
};
