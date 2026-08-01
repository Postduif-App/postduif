<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a member wants to hear about when they are not looking.
     *
     * notify_after_minutes is the whole switch: null means never, and a number
     * means "tell me about a channel I have not opened for this long". Minutes
     * rather than hours, so the interface can offer half an hour without the
     * column having to change its mind about what it stores.
     *
     * The two delivery flags are separate rather than one enum: wanting both is
     * the ordinary case, and an enum would need a "both" member that says
     * nothing the pair does not already say.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('notify_after_minutes')->nullable()->after('suspended_at');
            $table->boolean('notify_via_mail')->default(true)->after('notify_after_minutes');
            $table->boolean('notify_via_pushover')->default(false)->after('notify_via_mail');
            // Encrypted: it is a credential for somebody's own device, and this
            // table is read for a great many reasons other than sending a push.
            $table->text('pushover_user_key')->nullable()->after('notify_via_pushover');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'notify_after_minutes',
                'notify_via_mail',
                'notify_via_pushover',
                'pushover_user_key',
            ]);
        });
    }
};
