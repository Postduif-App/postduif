<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saying "I am done with this thread" and meaning it.
     *
     * closed_at could not carry this. It means "done with the conversation as
     * it stands", and FindActiveThreads undoes it the moment somebody replies
     * — which is right for the sidebar and is exactly why it cannot also mean
     * "stop telling me". Two intentions, two columns.
     *
     * closed_at becomes nullable in the same breath, because a member can now
     * arrive at this table without ever having closed anything: muting a live
     * thread is its own decision, and forcing a closed_at to go with it would
     * make the sidebar hide a thread somebody only asked to be quiet about.
     */
    public function up(): void
    {
        Schema::table('thread_user', function (Blueprint $table) {
            $table->timestamp('muted_at')->nullable()->after('closed_at');
        });

        DB::statement('alter table thread_user alter column closed_at drop not null');
    }

    public function down(): void
    {
        // A row that only ever meant "muted" has no closed_at to fall back on,
        // and the column is about to demand one.
        DB::table('thread_user')->whereNull('closed_at')->delete();

        DB::statement('alter table thread_user alter column closed_at set not null');

        Schema::table('thread_user', function (Blueprint $table) {
            $table->dropColumn('muted_at');
        });
    }
};
