<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where to report back about this one contract.
     *
     * Beside the workspace-wide subscriptions in contract_webhooks rather than
     * instead of them, because the two answer different questions. A
     * subscription is a standing arrangement a beheerder made on a settings
     * screen: "tell this address about everything". These two columns are the
     * other shape entirely — a system that sent one contract through the API and
     * wants to hear about that one, addressed to a URL it made up for the
     * occasion. Expecting it to first register a workspace-wide subscription,
     * and then work out which of the news is about its own contract, is the kind
     * of API that makes people write polling loops instead.
     *
     * Both nullable, and nearly every contract has neither: this is only ever
     * filled in by the API endpoint that accepts a contract from outside.
     *
     * The secret is encrypted for the same reason the subscription's is — see
     * that migration — and it is a column of its own rather than a reuse of the
     * caller's API token. Signing with the token would mean the receiving end
     * has to hold a credential that can also send contracts in order to check a
     * signature, which is a great deal of authority to hand to the part of a
     * system that only reads.
     */
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('callback_url', 2048)->nullable()->after('notify_channel_id');
            $table->text('callback_secret')->nullable()->after('callback_url');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['callback_url', 'callback_secret']);
        });
    }
};
