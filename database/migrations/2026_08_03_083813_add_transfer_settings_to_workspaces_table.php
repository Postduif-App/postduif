<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Two gigabytes: the point of a transfer is the file that will not fit in a message. */
    private const DEFAULT_MAX_KB = 2097152;

    /** A fortnight, the longest a link may be asked to live. */
    private const DEFAULT_MAX_DAYS = 14;

    /**
     * The ceilings a workspace puts on what may be sent out of it.
     *
     * Columns rather than feature flags, for the reason WorkspaceFeature spells
     * out: a flag can only say yes or no, and these carry a number. They sit
     * beside the attachment settings but are not the same settings — an
     * attachment is bounded by what a conversation can carry, and a transfer by
     * what the disk can spare until it expires.
     *
     * The days ceiling is what keeps the required expires_at on transfers from
     * being a formality: without it, "expires" would mean "in the year 2200".
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->unsignedInteger('max_transfer_kb')
                ->default(self::DEFAULT_MAX_KB)
                ->after('max_attachment_kb');

            $table->unsignedSmallInteger('max_transfer_days')
                ->default(self::DEFAULT_MAX_DAYS)
                ->after('max_transfer_kb');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['max_transfer_kb', 'max_transfer_days']);
        });
    }
};
