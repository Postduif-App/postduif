<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // First match wins, so the order is the rule rather than a display
            // preference: "status Y outside those hours" is simply a rule that
            // covers everything, sitting underneath the one that does not.
            $table->unsignedInteger('position')->default(0);

            /*
             * Which weekdays, ISO-numbered (1 = Monday). Empty means every day.
             *
             * jsonb rather than json: Postgres has no equality operator for
             * json, and one json column on a table is enough to break every
             * "select distinct" — which Filament writes on its own.
             */
            $table->jsonb('days')->default('[]');

            /*
             * The window, as a wall clock in the member's own timezone. Null on
             * both means the whole day.
             *
             * A window whose end is before its start runs through midnight, and
             * belongs to the day it began on: 22:00-06:00 on Monday is Monday
             * evening, not Monday morning.
             */
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();

            $table->string('status_emoji')->nullable();
            $table->string('status_text')->nullable();
            $table->string('availability');

            $table->timestamps();

            // Every lookup is "this member's rules, in order".
            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_rules');
    }
};
