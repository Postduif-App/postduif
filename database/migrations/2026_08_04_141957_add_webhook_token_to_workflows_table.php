<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a workflow with a webhook trigger needs, as columns on the workflow
     * rather than as keys in trigger_config.
     *
     * The hash has to be an index — the endpoint finds a workflow by it on
     * every request — and an index into a JSON column is a promise about the
     * shape of a free-form bag. The rest keeps it company because they are the
     * same fact told three ways, and splitting them across two storage kinds
     * would be worse than either.
     *
     * All nullable: five triggers out of six never touch any of this.
     */
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            /*
             * A plain sha256, deliberately not a password hash — the same
             * reasoning as Webhook::hashToken. The endpoint has to *find* a row
             * by this, so it must be deterministic and unsalted, which is safe
             * here in a way it never is for passwords: the token is 48 random
             * characters, so there is no dictionary to run through.
             */
            $table->string('webhook_token_hash')->nullable()->unique();

            // Encrypted rather than plain, so the column is unreadable without
            // the APP_KEY. Kept at all so somebody can see their own URL again.
            $table->text('webhook_token')->nullable();

            /*
             * The last body that arrived, so whoever writes the steps can see
             * which fields a sender actually sends. A sample to read once while
             * wiring up, not a log — a sender may post anything at all, names
             * and addresses included, so it holds one and goes when the
             * workflow goes.
             */
            $table->jsonb('webhook_payload')->nullable();

            $table->timestamp('webhook_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_token_hash',
                'webhook_token',
                'webhook_payload',
                'webhook_used_at',
            ]);
        });
    }
};
