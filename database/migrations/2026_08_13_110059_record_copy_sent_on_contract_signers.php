<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this person was sent the finished document.
     *
     * Per signer rather than one flag on the contract, and that is the whole
     * point of the column. RenderSignedContractJob may run three times: if the
     * transport gives out halfway down a list of five, a retry has to be able
     * to pick up at the third one. A single flag on the contract could only say
     * "someone got it" — which is either a duplicate for the first two or
     * nothing at all for the last three.
     *
     * It earns its place twice, the way reminded_at does: the detail screen can
     * say per person when their copy went out, which is the question somebody
     * asks before pressing "opnieuw versturen".
     */
    public function up(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->timestamp('copy_sent_at')->nullable()->after('reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->dropColumn('copy_sent_at');
        });
    }
};
