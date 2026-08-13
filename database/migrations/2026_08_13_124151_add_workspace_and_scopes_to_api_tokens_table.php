<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Narrow what a token may reach, without narrowing the ones already out.
     *
     * A token has meant one thing until now: this person, everywhere they can
     * go. That is the right key for an AI client sitting beside somebody all
     * day, and the wrong one for a script that exists to file contracts in a
     * single workspace — which is what is about to be pointed at this API.
     *
     * Both columns are nullable, and null is not "unset" here but a meaning of
     * its own. Given a default instead, every token already pasted into
     * somebody's config file would have to be reissued to keep working, for a
     * feature they never asked for.
     *
     *   workspace_id null — the member's own reach, all of it, exactly as
     *                       before. The status, channels and messages endpoints
     *                       are written against that and stay written against
     *                       it.
     *   workspace_id set  — one workspace, and the middleware refuses the token
     *                       the moment that membership ends.
     *
     *   scopes null       — the same as before as well: everything the member
     *                       may do through the endpoints that existed when this
     *                       column did not. Deliberately *not* a key to
     *                       anything asking for a scope by name — an endpoint
     *                       that demands one demands it of every token, and a
     *                       null that quietly satisfied it would hand contract
     *                       signing to every token minted last month.
     *   scopes set        — that list, and nothing beyond it.
     */
    public function up(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            /*
             * Cascading rather than nulling on delete. A token nulled back to
             * "all workspaces" when its workspace is dropped would silently
             * widen, which is the one direction a permission column must never
             * move on its own.
             */
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * jsonb rather than json, for the reason the rest of this schema
             * uses it: Postgres has no equality operator for json, so a plain
             * json column cannot be compared or indexed later without a cast.
             *
             * A list of strings rather than a pivot to a scopes table. There is
             * one scope today and the set is written in the application, not by
             * anybody using it — a table would be a join to read a constant.
             */
            $table->jsonb('scopes')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('api_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn('scopes');
        });
    }
};
