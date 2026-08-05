<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A face for the thing a workspace built, and a way for a message to find it.
     *
     * The avatar itself is a path on the workflow, exactly as a member's and a
     * workspace's are — stored small and served through a route, never off a
     * public disk. See StoreAvatar, which now squares one more kind of owner.
     *
     * The column on messages is the interesting half. A bot message carries its
     * sender's *name* as a copy, on purpose: renaming a workflow says something
     * about what it does next rather than about what it already said, and a
     * message outlives the thing that sent it. A face is the other way round.
     * Nobody wants last month's messages keeping a picture the workflow no
     * longer uses, and a copy per message would be a thousand rows to rewrite
     * every time somebody changes one.
     *
     * So the name is copied and the face is pointed at — which is precisely the
     * shape webhook_id already has beside bot_name, for the same reason. Null on
     * delete: the workflow can go, the message stays, and it falls back to the
     * default bot mark the way every bot message did until now.
     */
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('bot_name');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('workflow_id')->nullable()->after('webhook_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
        });

        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
