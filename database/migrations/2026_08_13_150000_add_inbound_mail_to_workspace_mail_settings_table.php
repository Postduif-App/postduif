<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a workspace's incoming mail lands.
     *
     * Beside the outgoing settings rather than in a table of its own, because
     * it is the same decision from the other side: a workspace that sends from
     * its own domain is the one that will want mail to that domain coming back
     * in. Keeping the pair together also means one screen, and one place where
     * "does this workspace do its own mail" is answered.
     *
     * Nothing here is on by default and nothing can be switched on by accident.
     * A workspace receives mail only once somebody has both generated the token
     * and chosen a channel — see the accessor on the model, which requires
     * both, rather than an is_enabled column that could disagree with them.
     */
    public function up(): void
    {
        Schema::table('workspace_mail_settings', function (Blueprint $table) {
            /*
             * The secret in the endpoint's own URL, which is the whole of what
             * authenticates an incoming delivery.
             *
             * A path segment rather than a signature over the body, and that is
             * a deliberate narrowing: every provider posts a different shape,
             * several of them re-encode what they send, and a signature scheme
             * that has to be right for all of them is one that will be subtly
             * wrong for one. A long random token in a URL that only the
             * provider is ever given is the version that cannot be got wrong.
             *
             * Unique, so the lookup is by this column alone: a token that
             * matched two workspaces would be a delivery that lands in whichever
             * row the database felt like returning.
             */
            $table->string('inbound_token', 64)->nullable()->unique()->after('verified_at');

            /*
             * The channel new tickets open in. Nullable and nullOnDelete: a
             * channel can be deleted, and the honest result is that the
             * workspace stops receiving rather than that deliveries pile up
             * against a channel that is gone.
             */
            $table->foreignId('inbound_channel_id')->nullable()->after('inbound_token')
                ->constrained('channels')->nullOnDelete();

            /*
             * The address people are told to write to, kept so the settings
             * screen can show it and so replies can be threaded: a ticket's
             * reply-to is this address with +t<number> in it.
             *
             * Stored rather than derived, because the application does not know
             * it — the workspace arranged the forwarding at their provider, and
             * only they can say which address ends up here.
             */
            $table->string('inbound_address')->nullable()->after('inbound_channel_id');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_mail_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inbound_channel_id');
            $table->dropColumn(['inbound_token', 'inbound_address']);
        });
    }
};
