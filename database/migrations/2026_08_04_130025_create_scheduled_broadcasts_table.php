<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One announcement, waiting, for however many channels it is meant for.
     *
     * One row rather than one per channel, and the reason is what happens in
     * between. BroadcastToChannels checks the sender's right to post at the
     * moment of sending — a tag can expand to a channel somebody may read but
     * not write in. Fanning out at scheduling time would freeze that answer a
     * week early and post into a channel they are no longer allowed to.
     *
     * It also makes withdrawing one row instead of hunting down six that have
     * nothing binding them together.
     */
    public function up(): void
    {
        Schema::create('scheduled_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('send_at');

            /*
             * Both nullable and both meaning "over", kept apart so the list can
             * say which it was — the same shape ScheduledMessage uses, and a
             * transfer distinguishes expiry from withdrawal the same way.
             */
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            // What the dispatcher asks every minute: what is due and not yet
            // gone. The sender is the leading column nowhere, because nobody
            // queries by sender without also being in one workspace.
            $table->index(['send_at', 'sent_at']);
        });

        Schema::create('scheduled_broadcast_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            // A channel is either in the announcement or it is not; naming it
            // twice is the same announcement.
            $table->unique(['scheduled_broadcast_id', 'channel_id'], 'scheduled_broadcast_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_broadcast_channel');
        Schema::dropIfExists('scheduled_broadcasts');
    }
};
