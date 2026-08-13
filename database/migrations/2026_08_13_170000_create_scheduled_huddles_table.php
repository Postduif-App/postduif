<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A huddle somebody has put in the diary.
     *
     * Its own table rather than a nullable starts_at on huddles, because the
     * two are different things that happen to end up in the same room. A huddle
     * is people talking right now — it has participants, it is swept when their
     * browsers go quiet, and it has no title because nobody names a
     * conversation they are already having. This is an appointment: it has a
     * name, a list of people who have not arrived, and it exists precisely
     * while nothing is happening.
     *
     * They meet in huddle_id, which is filled in the moment the appointment
     * turns into a conversation. Before that it is null and there is nothing to
     * join; afterwards the row is a record of what the huddle was called and
     * who was asked.
     */
    public function up(): void
    {
        Schema::create('scheduled_huddles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * What it is about. Required, unlike a huddle's non-existent name:
             * an appointment in a list beside four others has to be tellable
             * apart, and "huddle om 14:00" is not a thing anybody can decide to
             * attend.
             */
            $table->string('title');

            $table->timestamp('starts_at');

            /*
             * How long it is meant to take, in minutes. Not enforced anywhere —
             * nothing hangs up on anybody — but it is what lets the channel say
             * "14:00–14:30" instead of an open-ended time, which is the
             * difference between an invitation people accept and one they put
             * off deciding about.
             */
            $table->unsignedSmallInteger('duration_minutes')->default(30);

            /*
             * The conversation this became, once it started. Null until then.
             * nullOnDelete rather than cascade: a huddle is swept away when the
             * last browser leaves, and the appointment should outlive it —
             * that row is the only thing that still knows what the meeting was
             * called.
             */
            $table->foreignId('huddle_id')->nullable()->constrained()->nullOnDelete();

            /*
             * When the channel was told it had started. The claim the dispatcher
             * makes before it posts, so two overlapping runs cannot announce the
             * same appointment twice — the same shape the scheduled-message
             * dispatcher uses.
             */
            $table->timestamp('announced_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            // What the dispatcher asks for: the ones not yet announced whose
            // moment has come.
            $table->index(['announced_at', 'starts_at']);
        });

        /*
         * Who was asked. A table rather than "everybody in the channel",
         * because the case this exists for is a channel of thirty where four
         * people need half an hour — and an appointment that pinged all thirty
         * is one nobody makes twice.
         *
         * An empty list is meaningful and allowed: it means the channel at
         * large, which is what a stand-up in a small channel actually is.
         */
        Schema::create('scheduled_huddle_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scheduled_huddle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unique(['scheduled_huddle_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_huddle_user');
        Schema::dropIfExists('scheduled_huddles');
    }
};
