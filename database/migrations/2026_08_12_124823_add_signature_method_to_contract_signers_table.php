<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the mark on this contract was made.
     *
     * Part of the audit trail rather than a rendering hint. What is stored is a
     * PNG either way — see SignatureMethod for why the renderer deliberately has
     * only one code path — so nothing in the finished document can be read back
     * to tell a drawn signature from a typed one. Once these bytes are a
     * picture, that fact is gone unless it was written down at the moment it
     * was true.
     *
     * Nullable because most rows never carry one: a signer who was invited and
     * has not been round yet has made no mark, and null is the honest answer
     * rather than a default that would read as "getekend".
     */
    public function up(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->string('signature_method', 10)->nullable()->after('user_agent');

            /*
             * The name as it was typed, for a typed signature and nothing else.
             *
             * Kept beside the image rather than only inside it, because a
             * picture of text is not text: an audit trail that has to say "hij
             * typte Anna de Vries" cannot read that back out of a PNG, and
             * neither can anybody searching for it later.
             */
            $table->string('signature_text')->nullable()->after('signature_method');
        });
    }

    public function down(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->dropColumn(['signature_method', 'signature_text']);
        });
    }
};
