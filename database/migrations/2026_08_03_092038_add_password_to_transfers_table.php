<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Something the recipient knows, beside the link they hold.
     *
     * Independent of the audience rather than a fourth option in it, and that
     * is the point: a password is worth having precisely on an open link, where
     * the sender cannot say in advance who will be holding it. It combines with
     * the narrower audiences too, for the sender who wants both.
     *
     * Hashed, like any other password. Never readable back — not by the
     * recipient, and not by the sender either, who has to be able to say "I
     * sent it to you over the phone" rather than look it up here.
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('password')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
