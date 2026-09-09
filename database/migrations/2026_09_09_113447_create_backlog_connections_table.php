<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row is one workspace's arrangement with one Backlog installation: the
     * address to call, the client credentials to call it with, the secret that
     * verifies what Backlog calls back with, and which channel a synced ticket
     * lands in. `client_secret`, `access_token` and `webhook_secret` are
     * encrypted at the model (ApiToken::token's pattern) — unreadable without
     * the APP_KEY.
     */
    public function up(): void
    {
        Schema::create('backlog_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            $table->string('backlog_url');

            // The client-credentials grant this server authenticates with.
            // client_id travels in plain sight of anybody reading the settings
            // screen — it names the integration, not a secret — client_secret
            // does not.
            $table->string('client_id');
            $table->text('client_secret');

            // A cached access token, minted from the credentials above, so a
            // sync does not have to authenticate on every call.
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();

            // Verifies X-Backlog-Signature on the way in. Never looked up by —
            // the connection is already known from the webhook URL — so unlike
            // Webhook::token_hash there is nothing to index, only to decrypt.
            $table->text('webhook_secret');

            // Which events this connection wants delivered, e.g.
            // ["issue.created", "issue.updated"].
            $table->json('events');

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('consecutive_failures')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backlog_connections');
    }
};
