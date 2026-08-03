<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A question put to a channel.
     *
     * Keyed by ULID like Message, Transfer and SecretRequest, and for the same
     * reason: it lands in a conversation and its id travels in a URL.
     *
     * Note what is absent — anything anonymous. A vote here is attributable,
     * the way a reaction is, and that is a decision rather than an oversight:
     * "who is in favour" is usually the useful half of a poll in a work
     * channel. What the interface owes people in return is saying so before
     * they click, not after.
     */
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            // The asker keeps the poll alive after they leave: a question the
            // channel already answered should not disappear with them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('question');

            // Whether somebody may tick more than one answer. A flag rather
            // than two kinds of poll: everything else about them is identical.
            $table->boolean('allows_multiple')->default(false);

            /*
             * When it stops taking votes on its own, and when somebody stopped
             * it by hand. Both nullable and both meaning "closed" — kept apart
             * so the card can say which it was, the same way a transfer
             * distinguishes expiry from withdrawal.
             *
             * No scheduled command behind closes_at: a poll is closed when the
             * moment has passed, worked out where it is read.
             */
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['channel_id', 'created_at']);
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('poll_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->unsignedSmallInteger('position')->default(0);

            // The same answer twice is a mistake, not a choice.
            $table->unique(['poll_id', 'label']);
        });

        Schema::create('poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            /*
             * One vote per person per option, enforced here rather than in PHP:
             * clicking twice is one vote, and a double click must not become
             * two rows.
             *
             * What this cannot enforce is "one option per person" on a
             * single-choice poll — the poll is a column away, on the option. So
             * that rule is kept by CastVote, which replaces the old row inside
             * the same transaction. Worth knowing when reading that action: the
             * database is not backing it up here.
             */
            $table->unique(['poll_option_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_options');
        Schema::dropIfExists('polls');
    }
};
