<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When making the signed copy last went wrong.
     *
     * The status column is deliberately not used for this, and that is the
     * whole point of the column existing. A contract whose PDF could not be
     * composed is still signed: somebody put their name under a document and
     * the record of that is complete and unharmed. Turning it back to anything
     * other than Completed because a rendering step stumbled would be losing a
     * signature to a failure that has nothing to do with it.
     *
     * So the failure sits beside the status rather than in it, and the overview
     * reads it to say "de ondertekende versie kon niet gemaakt worden" next to
     * a contract that is otherwise perfectly finished.
     *
     * Cleared on a successful run, so it always means "the last attempt
     * failed" rather than "an attempt failed once".
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->timestamp('render_failed_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('render_failed_at');
        });
    }
};
