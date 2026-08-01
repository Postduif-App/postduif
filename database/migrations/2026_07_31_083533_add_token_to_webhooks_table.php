<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the token itself, encrypted, next to its hash.
     *
     * This reverses what the original table said: the plain value was shown
     * once and then gone for good. A URL nobody can look up again is a URL
     * people write down somewhere worse, and an integration that stops working
     * becomes a webhook you have to replace rather than one you can check.
     *
     * The trade-off, stated plainly: anyone holding both this table and the
     * APP_KEY can read these tokens, where before the table was worth nothing
     * on its own. The hash stays and is still what the endpoint looks up, so
     * this column is only ever read to show somebody a URL they may already
     * see.
     *
     * Nullable because webhooks created before this point have no recoverable
     * token — theirs was never stored. The interface offers those a fresh one
     * rather than pretending it can produce the old.
     */
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('token')->nullable()->after('token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropColumn('token');
        });
    }
};
