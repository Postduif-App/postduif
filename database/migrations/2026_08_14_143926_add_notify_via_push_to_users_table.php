<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a member wants the browser itself to tell them.
     *
     * A flag of its own beside notify_via_mail and notify_via_pushover rather
     * than an entry in an enum, for the reason those two are separate: wanting
     * more than one delivery method at once is the ordinary case.
     *
     * Off by default, and it has to be. Web push cannot be switched on from a
     * settings screen alone — the browser has to be asked for permission and
     * hand back a subscription — so a default of true would describe a wish the
     * application has no way of honouring.
     *
     * A member's own setting rather than a workspace one: how much interruption
     * you want is not something an administrator gets to decide for you.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_via_push')->default(false)->after('notify_via_pushover');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_via_push');
        });
    }
};
