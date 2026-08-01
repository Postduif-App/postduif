<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A token that lets an AI client act as one member.
     *
     * Built on the same shape as the webhook tokens rather than on a package:
     * a hash to look the token up by, and an encrypted copy so somebody can
     * see their own token again after they close the tab. Sanctum and Passport
     * would both do this, and both are a lot of surface for an application
     * that has no other API.
     *
     * The difference with a webhook, and it is the whole difference: a webhook
     * posts as a bot into one channel, where this acts as a person across
     * everything that person may see. So it belongs to a user rather than to a
     * channel, and every tool behind it asks the same policies the web app
     * does.
     */
    public function up(): void
    {
        Schema::create('mcp_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What it is for, in the member's own words: "Claude op mijn
            // laptop". A list of three tokens with no names is a list nobody
            // dares revoke anything from.
            $table->string('name');

            $table->string('token_hash', 64)->unique();
            $table->text('token')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_tokens');
    }
};
