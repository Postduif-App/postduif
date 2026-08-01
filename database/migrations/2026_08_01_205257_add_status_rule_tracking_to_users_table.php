<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * Which rule's window the status showing right now belongs to.
             *
             * Null when no rule covers this moment. Set to null rather than
             * deleted along with the rule: the status stays on screen until the
             * next run decides otherwise, and a dangling id would make that run
             * think a rule it cannot find is still in force.
             */
            $table->foreignId('status_rule_id')
                ->nullable()
                ->after('availability')
                ->constrained('status_rules')
                ->nullOnDelete();

            /*
             * Whether the member typed this status themselves.
             *
             * True for everybody who already has one, because until now that
             * was the only way to get one. Together with the id above this is
             * what makes "your own status wins until the next boundary" work:
             * an override is remembered as belonging to the window it was made
             * in, and the moment that window ends the schedule takes back over.
             */
            $table->boolean('status_is_manual')->default(true)->after('status_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_rule_id');
            $table->dropColumn('status_is_manual');
        });
    }
};
