<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether anyone may answer in a thread here.
     *
     * The "second setting" ChannelPolicy::reply() has been pointing at: posting
     * and answering were deliberately split so an admins-only channel stays
     * answerable, which left no way to run a channel that announces and does
     * not discuss. A company's news feed is exactly that.
     *
     * On by default: a conversation nobody may answer is the exception, and an
     * existing channel has never asked to become one.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->boolean('replies_open')->default(true)->after('posting_policy');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('replies_open');
        });
    }
};
