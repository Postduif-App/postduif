<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A few lines somebody writes about themselves.
     *
     * Nullable and short. Nullable because most people never fill it in and an
     * empty string would make "has written something" a comparison against ''
     * everywhere it is read. Short because this sits beside a name in a panel:
     * what it is for is "backend, mostly in the API" or "op dinsdag vrij", not
     * a career history.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bio', 280)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bio');
        });
    }
};
