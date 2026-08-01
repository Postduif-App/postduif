<?php

use App\Enums\Availability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status_emoji', 16)->nullable()->after('suspended_at');
            $table->string('status_text', 100)->nullable()->after('status_emoji');

            $table->string('availability')
                ->default(Availability::Available->value)
                ->after('status_text');

            /*
             * The last handful of statuses this member set, newest first, so
             * the picker can offer them back. Kept on the user rather than in a
             * table of its own: it is a short list nobody queries across, and
             * one column beats a join for something only ever read whole.
             *
             * jsonb rather than json, which is not a detail: Postgres has no
             * equality operator for json, so any "select distinct users.*" —
             * and the admin panel builds those — fails outright the moment a
             * plain json column is on the table.
             */
            $table->jsonb('recent_statuses')->default('[]')->after('availability');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'status_emoji',
                'status_text',
                'availability',
                'recent_statuses',
            ]);
        });
    }
};
