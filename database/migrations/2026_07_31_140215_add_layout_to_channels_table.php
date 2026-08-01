<?php

use App\Enums\ChannelLayout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the channel reads: a conversation, or a feed.
     *
     * Its own column rather than a fourth value in type. That one says who may
     * see the channel — and since the visibility switch writes it, a feed
     * folded in there could never be private. See ChannelLayout.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('layout', 16)
                ->default(ChannelLayout::Chat->value)
                ->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('layout');
        });
    }
};
