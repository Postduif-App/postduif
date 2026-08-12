<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a workspace's own mail leaves from.
     *
     * A table of its own rather than a dozen columns on workspaces. Most of
     * these are null for most workspaces — only the chosen transport's fields
     * are ever filled in — and three of them are secrets, which is a good
     * reason to keep them off the row that half the application selects.
     *
     * One row per workspace, enforced by the unique index rather than by
     * whoever writes it. There is no version history here and no second
     * profile: "the mail settings" is a single answer, and firstOrNew on a
     * unique column is what makes it stay one.
     *
     * The three token columns are text rather than string because they hold
     * ciphertext — Laravel's encrypted cast produces a base64 payload several
     * times the length of the secret, and a 255-character column would silently
     * become the limit on what an API key may be.
     */
    public function up(): void
    {
        Schema::create('workspace_mail_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('transport')->default('default');

            /*
             * Who the mail says it is from. Nullable, and then the application's
             * own from-address is used — a workspace that points this at its own
             * domain without having set the transport up to send for that domain
             * would otherwise have every message fail SPF.
             */
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();

            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();

            $table->text('postmark_token')->nullable();
            /*
             * Postmark separates transactional from broadcast traffic into
             * streams, and sending to the wrong one is how a workspace ends up
             * with its invitations counted as marketing. Optional: an account
             * that never made a second stream has nothing to choose.
             */
            $table->string('postmark_message_stream')->nullable();

            $table->text('lettermint_token')->nullable();
            $table->string('lettermint_route_id')->nullable();

            /*
             * The last test and how it went. Kept because the question this
             * screen actually answers is "did this ever work", and a flash
             * message that disappears on the next page load cannot answer it.
             */
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_mail_settings');
    }
};
