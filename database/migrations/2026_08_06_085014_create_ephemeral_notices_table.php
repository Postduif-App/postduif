<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A line in a channel that only one person sees.
     *
     * Its own table rather than a column on messages, and that is the whole
     * decision. A "only visible to you" flag over there would have to be
     * honoured by every path that reads a message — the feed, threads, pins,
     * search, the unread counts, the inbox, bookmarks, the MCP tools, the admin
     * panel — and by all six broadcast events, which run on the queue with
     * nobody signed in and go out over a channel-wide presence channel. One
     * missed path is somebody's private line showing up in a colleague's chat.
     *
     * Here nothing reads it except the one query that goes looking for it. What
     * it costs is that a notice can never become an ordinary message: no
     * replies, no reactions, no saving, no pinning. That is exactly what these
     * are — a receipt, not a contribution.
     */
    public function up(): void
    {
        Schema::create('ephemeral_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('body', 2000);
            /*
             * Who is speaking, so a receipt from a workflow can be signed with
             * the workflow's name rather than appearing to come from nobody.
             * A plain string: what said it may be gone by the time this is
             * read, and a foreign key would then take the notice with it.
             */
            $table->string('author_name', 80)->nullable();
            /*
             * When it stops being worth showing. Nullable for one that stays
             * until it is dismissed, which is what a receipt for something that
             * failed should do — the whole point of it is to be read.
             */
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // How they are fetched: this member's, in this channel, oldest
            // first. The one query there is.
            $table->index(['user_id', 'channel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ephemeral_notices');
    }
};
