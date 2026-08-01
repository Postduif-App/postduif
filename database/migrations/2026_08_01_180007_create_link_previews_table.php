<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a shared link turned out to be, cached by URL.
     *
     * By URL rather than by message: the same link lands in ten channels, and
     * fetching it ten times means telling the other side ten times that we are
     * looking. One row, and every message that carries the link reads it.
     *
     * A row is also written when the fetch fails or was refused, with
     * failed_reason filled in. Without that, a link that cannot be read would
     * be attempted again on every message that mentions it — which is exactly
     * the behaviour somebody hostile would want.
     */
    public function up(): void
    {
        Schema::create('link_previews', function (Blueprint $table) {
            $table->id();

            // Hashed as well as stored: the URL itself can be longer than an
            // index allows, and this is what lookups go through.
            $table->string('url', 2048);
            $table->string('url_hash', 64)->unique();

            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 2048)->nullable();
            $table->string('site_name')->nullable();

            $table->timestamp('fetched_at')->nullable();

            /*
             * Why there is nothing to show. Kept rather than left null, because
             * "we tried and it is not worth trying again" and "we have not
             * tried" are different states, and only the second one should lead
             * to a request going out.
             */
            $table->string('failed_reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_previews');
    }
};
