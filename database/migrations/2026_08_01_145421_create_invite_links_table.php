<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A way into a workspace that is not addressed to anybody.
     *
     * Deliberately not invitations with a nullable email: that table has a
     * unique index on (workspace_id, email) and an accepted_at that means it is
     * spent. A shareable link is the opposite of both — it has no address, and
     * being used is not the end of it.
     *
     * Three ways for a link to stop working, and each is stored separately so
     * the reason survives: it was revoked, its date passed, or it was used up.
     */
    public function up(): void
    {
        Schema::create('invite_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            // The maker is kept even after they leave, so the list stays
            // readable — hence nullOnDelete rather than a cascade that would
            // take working links down with a departing colleague.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('token', 64)->unique();
            $table->string('role')->default('member');

            // Null on both means unbounded: as often as you like, for as long
            // as you like. That is a choice the maker gets to make, so it is a
            // value rather than a sentinel number nobody would recognise.
            $table->unsignedInteger('max_uses')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'revoked_at']);
        });

        /*
         * The channels a link drops you into. A table rather than a column for
         * the same reason channel_invitation is one: a channel deleted before
         * the link is used drops out of it instead of leaving an id behind
         * that resolves to nothing.
         */
        Schema::create('channel_invite_link', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invite_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            $table->unique(['invite_link_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_invite_link');
        Schema::dropIfExists('invite_links');
    }
};
