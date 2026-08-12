<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this person was last nudged.
     *
     * A column rather than a rate limiter in the cache, and the difference
     * matters here. What this holds back is one person mailing another person
     * repeatedly, and a cache is a thing that gets flushed on every deploy —
     * which would turn "één herinnering per dag" into "één per dag, tenzij er
     * toevallig iets werd uitgerold".
     *
     * It also earns its place twice: the overview has to be able to say when
     * somebody was last reminded, because "heb ik hem al gemaand" is the first
     * question anybody asks before pressing the button.
     */
    public function up(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('declined_at');
        });
    }

    public function down(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
