<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether clocking in and out is allowed to move your status along with it.
     *
     * A preference rather than a rule, because both answers are reasonable and
     * neither is guessable. Clocking in and staying "afwezig" is a colleague
     * being told the wrong thing all morning; being flipped to "beschikbaar" by
     * a clock is somebody else being told they are reachable when they sat down
     * to concentrate. So it is asked.
     *
     * On the member and not on the membership: status is one thing across every
     * workspace somebody belongs to — there is a single availability column —
     * so a per-workspace answer would be a setting whose two halves could
     * contradict each other about the same status.
     *
     * Off unless somebody says otherwise. The clock's job is to record hours;
     * quietly rewriting what colleagues read about you is a second thing, and
     * the more surprising of the two to discover after the fact.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('clock_sets_status')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('clock_sets_status');
        });
    }
};
