<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which language somebody has asked for.
     *
     * Nullable, and that null is the useful state rather than a gap waiting to
     * be filled: it means "whatever my browser says". Defaulting the column to
     * 'nl' would make an English-speaking visitor's first screen Dutch, and the
     * setting they need to fix that would be in Dutch too.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
