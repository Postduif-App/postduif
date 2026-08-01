<?php

use App\Enums\ChannelPostingPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing channels have to keep behaving exactly as they did, so the
     * default is the open policy rather than the safe-looking one.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('posting_policy')
                ->default(ChannelPostingPolicy::Everyone->value)
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('posting_policy');
        });
    }
};
