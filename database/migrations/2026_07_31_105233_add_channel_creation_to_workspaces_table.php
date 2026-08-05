<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Everyone, so existing workspaces keep working exactly as they did
            // before the setting existed.
            $table->string('channel_creation')
                // The enum that spelled this is gone; a migration reads the value
                // it wrote at the time, not a class the application has since
                // dropped.
                ->default('everyone')
                ->after('broadcast_mentions');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('channel_creation');
        });
    }
};
