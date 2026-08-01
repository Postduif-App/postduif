<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An incoming webhook lets something outside the application post into one
     * channel, under a name it picks itself.
     *
     * Only the hash of the token is stored. The plain value is shown once, at
     * the moment it is created, and is unrecoverable afterwards — so a leak of
     * this table hands nobody the ability to post.
     */
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bot_name');
            $table->string('token_hash')->unique();
            // The creator is bookkeeping, not ownership: removing the member who
            // set an integration up must not quietly switch it off.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
