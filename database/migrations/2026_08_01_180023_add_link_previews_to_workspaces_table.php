<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether this workspace lets the server fetch what a shared link is.
     *
     * Off by default, unlike every other setting here, and the reason is not
     * tidiness. Fetching a preview means our server opens the link — visible at
     * the other end, with our address and the moment. For a link somebody
     * pasted into a private channel, that is a thing nobody decided to share.
     *
     * So it is a choice a workspace makes deliberately, rather than one it has
     * to discover it already made.
     */
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('link_previews_enabled')
                ->default(false)
                ->after('max_attachment_kb');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('link_previews_enabled');
        });
    }
};
