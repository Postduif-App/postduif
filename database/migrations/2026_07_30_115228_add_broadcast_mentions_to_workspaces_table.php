<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('broadcast_mentions')
                // The enum that spelled this is gone; a migration reads the value
                // it wrote at the time, not a class the application has since
                // dropped.
                ->default('admins')
                ->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('broadcast_mentions');
        });
    }
};
