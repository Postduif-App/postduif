<?php

use App\Enums\ChannelCreationPolicy;
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
                ->default(ChannelCreationPolicy::Everyone->value)
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
