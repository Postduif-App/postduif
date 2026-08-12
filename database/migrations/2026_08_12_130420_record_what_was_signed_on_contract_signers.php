<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What this person actually signed, and the rule that they only did it once.
     *
     * The hash is the point of the whole feature stated in one column. A
     * signature is worth what can be shown about the document it was put under,
     * and "het contract" is not a thing that can be pointed at later — the row
     * can be edited, the file can be replaced. A sha256 taken at the moment of
     * signing and kept on the signer's own row is the only version of that
     * claim which does not depend on anything staying still.
     *
     * Recorded per signer rather than only on the contract, and that is the
     * whole reason it is here instead of being read off the contract when
     * needed: three people sign at three moments, and the useful question is
     * what each of them saw — not what the file happens to hash to today.
     */
    public function up(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->char('signed_document_hash', 64)->nullable()->after('signed_at');
        });

        /*
         * One outcome per person, enforced where it cannot be argued with.
         *
         * A signer who has both signed and refused is not a state the
         * application can be in, and the reason to say so in the database
         * rather than in a policy is that a constraint holds against every
         * route to the row: a controller written next year, a tinker session, a
         * job that retries. This is the invariant the bead asks to be a
         * constraint rather than an if.
         *
         * What it deliberately does *not* enforce is "sign only once", and no
         * constraint can: "signed_at was null a moment ago" is a fact about the
         * past, and SQL constraints only see the row in front of them. That
         * guarantee comes from the conditional update in SignContract — one
         * statement, atomic, which either claims an unsigned row or reports
         * that it claimed nothing.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE contract_signers
            ADD CONSTRAINT contract_signers_one_outcome
            CHECK (signed_at IS NULL OR declined_at IS NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contract_signers DROP CONSTRAINT contract_signers_one_outcome');

        Schema::table('contract_signers', function (Blueprint $table) {
            $table->dropColumn('signed_document_hash');
        });
    }
};
