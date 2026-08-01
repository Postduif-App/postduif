<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who said it first, on a message that was carried here from elsewhere.
     *
     * A copied name rather than a foreign key to the original, and that is the
     * whole design in one column. A link would have to point at a message in a
     * channel the reader may well not be in — and then either break for them or
     * tell them that channel exists. Neither is something a forward should do.
     *
     * Copied for the same reason bot_name is: renaming or removing an account
     * later must not rewrite what a message said when it was sent.
     *
     * Deliberately absent: the channel it came from. Naming it would leak the
     * existence of a private channel to everyone in the target, which is
     * exactly the thing the person forwarding did not decide to share.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('forwarded_from')->nullable()->after('bot_name');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('forwarded_from');
        });
    }
};
