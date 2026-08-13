<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which language this contract's mail goes out in.
     *
     * Until now that was read off the author — see Contract::mailLocale — which
     * is a fair guess for a contract somebody sends from the screen and the
     * wrong one for a contract sent over the API. There the author is the
     * account behind the token, so a rental company mailing a German customer
     * on their behalf could only ever ask in the language of the machine that
     * pressed the button.
     *
     * A column rather than a value passed into the send, because the language
     * has to outlive the request. The invitation leaves now, the reminder from
     * the scheduler and the signed copy from a queued job — three moments with
     * no reader behind them, and a contract that asked in German and confirmed
     * in Dutch is worse than one that got it wrong consistently.
     *
     * Null means "whatever the author's language is", which is what every
     * contract sent from the screen still means and what every existing row
     * meant before this column existed.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Short on purpose: these are the tags HandleLocale::SUPPORTED
            // holds, and a column wide enough for a sentence would invite one.
            $table->string('mail_locale', 10)->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn('mail_locale');
        });
    }
};
