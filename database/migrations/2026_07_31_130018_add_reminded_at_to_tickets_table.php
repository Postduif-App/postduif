<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When this ticket was last nagged about.
     *
     * Without it the reminder runs hourly and says the same thing every hour,
     * which is how people learn to ignore it — and a reminder nobody reads is
     * worse than none, because it looks like the work is covered.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('first_responded_at');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
