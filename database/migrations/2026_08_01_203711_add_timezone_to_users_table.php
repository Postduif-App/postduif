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
             * The application runs on UTC, which is right for storing a moment
             * and useless for a clock that repeats: "every weekday at nine"
             * needs to know whose nine. Amsterdam as the default because that
             * is where this is used; anybody elsewhere changes it once.
             */
            $table->string('timezone')->default('Europe/Amsterdam')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
