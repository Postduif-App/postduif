<?php

use App\Enums\TransferAudience;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who the link works for.
     *
     * A column rather than a flag on the workspace: this is the sender's choice
     * per transfer, not a house rule. The same pile of files may go to a
     * customer one week and stay inside the company the next, and asking once
     * per transfer is the only place the question is actually answerable.
     *
     * Defaults to everyone, which is what the transfers made before this
     * migration were: they were handed out as open links, and quietly narrowing
     * them now would break links people are holding.
     */
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('audience')
                ->default(TransferAudience::Everyone->value)
                ->after('token');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
