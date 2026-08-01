<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this member keeps this channel at the top of their own sidebar.
     *
     * On the membership, beside muted_at, because it is the same kind of thing:
     * a decision about your own attention rather than about the channel. Two
     * people in one channel will disagree about both, and neither should be
     * able to change the other's mind by clicking.
     *
     * A timestamp rather than a boolean, for the same reason muted_at is one:
     * "since when" costs nothing to keep and answers the ordering question if
     * a long list of favourites ever needs one.
     */
    public function up(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->timestamp('favorited_at')->nullable()->after('muted_until');
        });
    }

    public function down(): void
    {
        Schema::table('channel_user', function (Blueprint $table) {
            $table->dropColumn('favorited_at');
        });
    }
};
