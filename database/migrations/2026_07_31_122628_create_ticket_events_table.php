<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();

            // Nullable, because not everything that happens to a ticket is done
            // by somebody: a webhook or a scheduled reminder leaves a trace here
            // too, and those have no member behind them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');

            // What changed, as {from, to} or {assignee}. Free-form on purpose:
            // the shape differs per event type, and pinning it into columns
            // would mean a migration for every new kind of change.
            $table->json('payload')->default('{}');

            // No updated_at and no soft delete: an event is what happened. There
            // is nothing to amend and nothing to withdraw.
            $table->timestamp('created_at')->nullable();

            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_events');
    }
};
