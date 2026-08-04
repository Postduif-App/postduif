<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The table was built for MCP clients and now carries the plain HTTP API too.
 *
 * A rename rather than an edit to the migration that made it: that one has
 * already run everywhere, and changing it in place would leave any database
 * that ran it with a table nothing looks for any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('mcp_tokens', 'api_tokens');
    }

    public function down(): void
    {
        Schema::rename('api_tokens', 'mcp_tokens');
    }
};
