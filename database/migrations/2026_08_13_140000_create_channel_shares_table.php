<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A channel one workspace has opened to another.
     *
     * The row is an agreement between two workspaces, not a membership: it says
     * that the people of the invited workspace *may* be put into this channel,
     * and nothing more. Who is actually in it stays in channel_user, exactly as
     * for anybody else — which is the whole reason messages, threads, reactions
     * and mentions needed no second code path to work across the boundary.
     *
     * Three timestamps rather than a status column, the way huddle_participants
     * keeps its two. Each one records something that happened at a moment and
     * that a screen wants to show ("uitgenodigd op", "geaccepteerd op"), and a
     * status string would throw the moment away and then need all three back.
     * The states they spell out:
     *
     *   none set            — invited, waiting on the other workspace
     *   accepted_at         — live; their people may be added to the channel
     *   declined_at         — they said no; the host may ask again
     *   revoked_at          — it is over, from whichever side ended it
     *
     * Deliberately no cross-installation half. A share points at a workspace by
     * id, so both sides live in this database and both sides are governed by
     * the same policies — federation between two separate Postduif servers is
     * a different feature with a different trust model, and pretending this
     * table is a step towards it would be the wrong shape to inherit.
     */
    public function up(): void
    {
        Schema::create('channel_shares', function (Blueprint $table) {
            $table->id();

            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            /*
             * The workspace being let in. Never the channel's own — see the
             * check below, which is there because "share with yourself" is not
             * a harmless no-op: it would give every member of the host a second
             * route into a private channel that the members table never
             * granted them.
             */
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();

            /*
             * Who asked and who answered. Nullable because both can leave the
             * workspace afterwards, and a share outliving the person who set it
             * up is normal rather than a fault.
             */
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * What the other side may do once they are in. Reading is the floor
             * and is not a column: a share that grants nothing is a share
             * nobody would make. Writing is, because the case this feature
             * exists for cuts both ways — a channel where a supplier reads
             * announcements and a channel where they answer are the same
             * arrangement with a different answer here.
             */
            $table->boolean('can_post')->default(true);

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            /*
             * One standing arrangement per pair. A second row for the same two
             * workspaces would make "is this shared with them" a question with
             * two answers, and the one the application happened to read first
             * would decide whether somebody may post.
             *
             * It also means a revoked share is re-offered by writing the same
             * row again rather than by piling up history beside it. That is a
             * deliberate loss: the table answers "what is the arrangement now",
             * and who once had access is a question for the audit log this
             * application does not have yet.
             */
            $table->unique(['channel_id', 'workspace_id']);
        });

        /*
         * "A workspace is never a guest in its own channel" belongs here as
         * well and cannot be written here: the rule compares two tables, and
         * PostgreSQL refuses a subquery in a check constraint. It lives in
         * ChannelShare::booted() instead, which is the nearest thing to a place
         * every writer has to pass through.
         */
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_shares');
    }
};
